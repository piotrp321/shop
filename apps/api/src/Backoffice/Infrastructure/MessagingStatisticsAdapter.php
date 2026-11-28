<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure;

use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Query\DatabaseQueues;
use App\Backoffice\Application\Query\FailedMessages;
use App\Backoffice\Application\Query\QueueStatus;
use App\Backoffice\Application\Query\TransportBacklog;
use App\Shared\Domain\BoundedCount;
use App\Shared\Infrastructure\Health\ServiceProbe;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The outbox is the `outbox` queue of the Doctrine transport table; the queues come from the management API of
 * RabbitMQ for the vhost of the `events` transport. `consumers` is what RabbitMQ reports: the number of AMQP subscribers
 * (basic.consume). The Messenger worker polls with basic.get, so it is 0 even while the worker runs.
 *
 * The other queues of the database are the Doctrine transports of messenger.yaml (`TRANSPORTS`, pinned to the
 * configuration by a test) and `failed`. Their times are the UTC moments Messenger writes to TIMESTAMP columns without
 * a time zone, so they are compared with the clock in UTC.
 *
 * What is ready is what a worker would take now, by the rule of the Doctrine transport: due (`available_at` not after now)
 * and not handed to a worker, or handed to one more than `redeliver_timeout` ago (a worker that died before the
 * acknowledgement). The timeout is the one of each transport in messenger.yaml (`$redeliverTimeouts`, pinned to the
 * configuration by a test), as the same message left behind by a dead worker has `delivered_at` set for good.
 */
final readonly class MessagingStatisticsAdapter implements MessagingStatistics
{
    private const string OUTBOX_QUEUE = 'outbox';
    private const string FAILED_QUEUE = 'failed';

    /** The Doctrine transports that a worker takes messages from, in the order they are reported. */
    public const array TRANSPORTS = ['outbox', 'scheduled', 'payment_simulator', 'promotions', 'jobs', 'indexing_finalize'];

    /** Messages counted in a queue: the count stops one above, so that it says "more than" without reading the queue. */
    private const int COUNT_LIMIT = 1000;

    public function __construct(
        private Connection $connection,
        private HttpClientInterface $client,
        private string $managementUrl,
        private string $user,
        #[\SensitiveParameter] private string $password,
        private string $vhost,
        private ClockInterface $clock,
        /** @var array<string, int> `redeliver_timeout` in seconds of every transport of TRANSPORTS (messenger.yaml) */
        private array $redeliverTimeouts,
    ) {
        foreach (self::TRANSPORTS as $transport) {
            if (!\is_int($this->redeliverTimeouts[$transport] ?? null) || $this->redeliverTimeouts[$transport] < 1) {
                throw new \InvalidArgumentException(\sprintf('The redeliver timeout of the transport "%s" is missing or not a positive number of seconds.', $transport));
            }
        }
    }

    public function outboxPending(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM messenger_messages WHERE queue_name = ?', [self::OUTBOX_QUEUE]);

        \assert(\is_int($count) || \is_string($count));

        return (int) $count;
    }

    public function databaseQueues(): DatabaseQueues
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));

        return new DatabaseQueues($this->failed(), array_map(fn (string $queue): TransportBacklog => $this->backlog($queue, $now), self::TRANSPORTS));
    }

    public function startQueues(): \Closure
    {
        try {
            $response = $this->client->request('GET', \sprintf('%s/api/queues/%s', rtrim($this->managementUrl, '/'), rawurlencode($this->vhost)), [
                'query' => ['columns' => 'name,messages,consumers'],
                'auth_basic' => [$this->user, $this->password],
                'timeout' => ServiceProbe::LIMIT_SECONDS,
                'max_duration' => ServiceProbe::LIMIT_SECONDS,
            ]);
            // The transfer begins once the client is driven: do it now, so the limit counts from here and the wait
            // overlaps with the probes (see HttpProbe::start()).
            foreach ($this->client->stream($response, 0.0) as $chunk) {
                $chunk->isTimeout(); // throws when the request has already failed
                break;
            }
        } catch (ExceptionInterface) {
            return static fn (): ?array => null;
        }

        return static function () use ($response): ?array {
            try {
                $data = $response->toArray();
            } catch (ExceptionInterface) {
                return null;
            }

            $queues = [];
            foreach ($data as $queue) {
                if (!\is_array($queue) || !\is_string($queue['name'] ?? null)) {
                    return null;
                }
                // A queue that has just been declared has no statistics yet.
                $queues[] = new QueueStatus($queue['name'], self::count($queue['messages'] ?? 0), self::count($queue['consumers'] ?? 0));
            }
            usort($queues, static fn (QueueStatus $a, QueueStatus $b): int => strcmp($a->name, $b->name));

            return $queues;
        };
    }

    private function failed(): FailedMessages
    {
        $rows = $this->connection->fetchOne(
            'SELECT count(*) FROM (SELECT 1 FROM messenger_messages WHERE queue_name = :queue LIMIT '.(self::COUNT_LIMIT + 1).') AS counted',
            ['queue' => self::FAILED_QUEUE],
        );
        $oldest = $this->connection->fetchOne('SELECT min(created_at) FROM messenger_messages WHERE queue_name = :queue', ['queue' => self::FAILED_QUEUE]);
        \assert(\is_int($rows) || \is_string($rows));
        \assert(null === $oldest || \is_string($oldest));

        return new FailedMessages(BoundedCount::ofRowsRead((int) $rows, self::COUNT_LIMIT), null === $oldest ? null : self::utc($oldest));
    }

    /**
     * The messages that are ready now, as Doctrine's `Connection::createAvailableMessagesQueryBuilder` takes them: due
     * and either not handed to a worker or handed to one before `now - redeliver_timeout` (strictly: exactly at the
     * limit it is still a worker's). The age counts from `available_at`, so a message left behind by a dead worker is as
     * old as it has been due. The oldest of the first 1001 by `available_at` is the oldest of all, and the index
     * (queue_name, available_at, delivered_at, id) delivers them in that order.
     */
    private function backlog(string $queue, \DateTimeImmutable $now): TransportBacklog
    {
        $row = $this->connection->fetchAssociative(
            'SELECT count(*) AS ready, min(available_at) AS oldest FROM ('
            .'SELECT available_at FROM messenger_messages WHERE queue_name = :queue AND available_at <= :now '
            .'AND (delivered_at IS NULL OR delivered_at < :redeliver) '
            .'ORDER BY available_at LIMIT '.(self::COUNT_LIMIT + 1)
            .') AS ready',
            [
                'queue' => $queue,
                'now' => $now->format('Y-m-d H:i:s'),
                'redeliver' => $now->modify(\sprintf('-%d seconds', $this->redeliverTimeouts[$queue]))->format('Y-m-d H:i:s'),
            ],
        );
        \assert(\is_array($row) && (\is_int($row['ready']) || \is_string($row['ready'])) && (null === $row['oldest'] || \is_string($row['oldest'])));

        return new TransportBacklog(
            $queue,
            BoundedCount::ofRowsRead((int) $row['ready'], self::COUNT_LIMIT),
            null === $row['oldest'] ? null : max(0, $now->getTimestamp() - self::utc($row['oldest'])->getTimestamp()),
        );
    }

    private static function utc(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    private static function count(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }
}
