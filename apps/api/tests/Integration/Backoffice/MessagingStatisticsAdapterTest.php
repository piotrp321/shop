<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backoffice;

use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Query\QueueStatus;
use App\Backoffice\Infrastructure\MessagingStatisticsAdapter;
use App\Catalog\Domain\Product\ProductCreated;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\Envelope;

/**
 * The adapter on the real transports of the `test_messaging` kernel: the outbox in `shop_test` and the Management API
 * of RabbitMQ for the `test` vhost.
 */
final class MessagingStatisticsAdapterTest extends MessagingKernelTestCase
{
    public function testOutboxPendingReflectsTheEventsWaitingToBeRelayed(): void
    {
        $before = $this->statistics()->outboxPending();

        $this->publisher()->publish(new ProductCreated('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', new \DateTimeImmutable()));

        self::assertSame($before + 1, $this->statistics()->outboxPending());
    }

    public function testOutboxPendingCountsOnlyTheOutboxQueue(): void
    {
        $before = $this->statistics()->outboxPending();
        $this->connection()->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('{}', '{}', 'failed', now(), now())",
        );

        self::assertSame($before, $this->statistics()->outboxPending());
    }

    public function testQueuesListsTheEventsQueueWithoutSubscribers(): void
    {
        $queues = $this->queues($this->statistics());

        self::assertNotNull($queues);
        $events = array_values(array_filter($queues, static fn (QueueStatus $queue): bool => 'events' === $queue->name));
        self::assertCount(1, $events);
        self::assertGreaterThanOrEqual(0, $events[0]->messages);
        // `consumers` is what RabbitMQ counts: AMQP subscribers (basic.consume). Messenger's worker polls with
        // basic.get, so nothing subscribes to the queue, in the tests and with the dev worker alike.
        self::assertSame(0, $events[0]->consumers);
    }

    public function testQueuesReflectTheMessagesInTheQueue(): void
    {
        $this->transport('events')->send(new Envelope(new ProductCreated('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', new \DateTimeImmutable())));

        // The Management API reports what the broker published at its last statistics interval (5 s by default).
        $messages = null;
        for ($attempt = 0; $attempt < 30 && 1 !== $messages; ++$attempt) {
            foreach ($this->queues($this->statistics()) ?? [] as $queue) {
                if ('events' === $queue->name) {
                    $messages = $queue->messages;
                }
            }
            if (1 !== $messages) {
                usleep(500_000);
            }
        }

        self::assertSame(1, $messages);
    }

    public function testQueuesAreNullWhenTheManagementApiDoesNotAnswer(): void
    {
        $adapter = $this->adapterWith('http://127.0.0.1:1');

        self::assertNull($this->queues($adapter));
    }

    public function testQueuesAreNullWhenTheManagementApiRejectsTheCredentials(): void
    {
        $adapter = $this->adapterWith($this->managementUrl(), password: 'wrong');

        self::assertNull($this->queues($adapter));
    }

    public function testQueuesAreNullForAVhostThatDoesNotExist(): void
    {
        $adapter = $this->adapterWith($this->managementUrl(), vhost: 'no-such-vhost');

        self::assertNull($this->queues($adapter));
    }

    public function testOutboxPendingStillWorksWhenTheManagementApiIsDown(): void
    {
        self::assertGreaterThanOrEqual(0, $this->adapterWith('http://127.0.0.1:1')->outboxPending());
    }

    /**
     * @return list<QueueStatus>|null
     */
    private function queues(MessagingStatistics $statistics): ?array
    {
        return ($statistics->startQueues())();
    }

    private function statistics(): MessagingStatistics
    {
        $statistics = self::getContainer()->get(MessagingStatistics::class);
        self::assertInstanceOf(MessagingStatistics::class, $statistics);

        return $statistics;
    }

    private function managementUrl(): string
    {
        return 'http://'.self::env('RABBITMQ_HOST').':15672';
    }

    private function adapterWith(string $managementUrl, ?string $password = null, string $vhost = 'test'): MessagingStatisticsAdapter
    {
        return new MessagingStatisticsAdapter(
            $this->connection(),
            HttpClient::create(),
            $managementUrl,
            self::env('RABBITMQ_USER'),
            $password ?? self::env('RABBITMQ_DEFAULT_PASS'),
            $vhost,
        );
    }

    private static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        self::assertIsString($value);

        return $value;
    }
}
