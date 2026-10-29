<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure;

use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Query\QueueStatus;
use App\Shared\Infrastructure\Health\ServiceProbe;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The outbox is the `outbox` queue of the Doctrine transport table; the queues come from the management API of
 * RabbitMQ for the vhost of the `events` transport. `consumers` is what RabbitMQ reports: the number of AMQP subscribers
 * (basic.consume). The Messenger worker polls with basic.get, so it is 0 even while the worker runs.
 */
final readonly class MessagingStatisticsAdapter implements MessagingStatistics
{
    private const string OUTBOX_QUEUE = 'outbox';

    public function __construct(
        private Connection $connection,
        private HttpClientInterface $client,
        private string $managementUrl,
        private string $user,
        #[\SensitiveParameter] private string $password,
        private string $vhost,
    ) {
    }

    public function outboxPending(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM messenger_messages WHERE queue_name = ?', [self::OUTBOX_QUEUE]);

        \assert(\is_int($count) || \is_string($count));

        return (int) $count;
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

    private static function count(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }
}
