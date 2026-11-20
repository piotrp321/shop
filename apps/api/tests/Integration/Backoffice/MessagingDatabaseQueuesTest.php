<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backoffice;

use App\Backoffice\Application\Query\TransportBacklog;
use App\Backoffice\Infrastructure\MessagingStatisticsAdapter;
use App\Shared\Domain\BoundedCount;
use App\Tests\Support\MessengerRows;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The statistics of the queues in the database (spec: docs/features/async-reliability/spec.md, W7) on the real table
 * `messenger_messages` of `shop_test`, with a clock that stands still. The adapter reads only the database: its HTTP
 * client must never be used.
 */
final class MessagingDatabaseQueuesTest extends KernelTestCase
{
    private const string NOW = '2026-11-20 12:00:00';

    /** `redeliver_timeout` of the Doctrine transports of messenger.yaml, in seconds. */
    private const int REDELIVER_TIMEOUT = 300;

    private Connection $connection;
    private int $httpRequests = 0;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        MessengerRows::clear($this->connection);
    }

    public function testWithNothingWaitingEveryQueueIsEmptyAndInTheFixedOrder(): void
    {
        $queues = $this->adapter($this->connection)->databaseQueues();

        self::assertEquals(new BoundedCount(0, false), $queues->failed->count);
        self::assertNull($queues->failed->oldestAt);
        self::assertSame(['outbox', 'scheduled', 'payment_simulator', 'promotions', 'jobs'], array_map(static fn (TransportBacklog $transport): string => $transport->name, $queues->transports));
        foreach ($queues->transports as $transport) {
            self::assertEquals(new TransportBacklog($transport->name, new BoundedCount(0, false), null), $transport);
        }
        self::assertSame(0, $this->httpRequests, 'The statistics of the database do not call the broker.');
    }

    public function testFailedMessagesAreCountedWithTheMomentTheOldestWasWritten(): void
    {
        MessengerRows::add($this->connection, 'failed', $this->at('-1 hour'), $this->at('-3 hours'));
        MessengerRows::add($this->connection, 'failed', $this->at('-1 minute'), $this->at('-20 minutes'));
        MessengerRows::add($this->connection, 'jobs', $this->at('-1 hour'), $this->at('-5 hours'));

        $failed = $this->adapter($this->connection)->databaseQueues()->failed;

        self::assertEquals(new BoundedCount(2, false), $failed->count);
        self::assertEquals(new \DateTimeImmutable('2026-11-20T09:00:00+00:00'), $failed->oldestAt);
        self::assertSame('+00:00', $failed->oldestAt?->format('P'));
    }

    public function testFailedMessagesAreCountedUpTo1000(): void
    {
        MessengerRows::add($this->connection, 'failed', times: 1000);
        self::assertEquals(new BoundedCount(1000, false), $this->adapter($this->connection)->databaseQueues()->failed->count);

        MessengerRows::add($this->connection, 'failed');
        self::assertEquals(new BoundedCount(1000, true), $this->adapter($this->connection)->databaseQueues()->failed->count);
    }

    public function testTheAgeOfAQueueIsCountedFromTheOldestMessageThatIsReady(): void
    {
        MessengerRows::add($this->connection, 'outbox', $this->at('-90 seconds'));
        MessengerRows::add($this->connection, 'outbox', $this->at('-10 seconds'));

        $outbox = $this->transport('outbox');

        self::assertEquals(new TransportBacklog('outbox', new BoundedCount(2, false), 90), $outbox);
    }

    public function testADelayedMessageIsAsOldAsTheMomentItBecameReadyNotAsItsCreation(): void
    {
        // ExpireUnpaidOrder: written 35 minutes ago, due since a second.
        MessengerRows::add($this->connection, 'scheduled', $this->at('-1 second'), $this->at('-35 minutes'));

        self::assertEquals(new TransportBacklog('scheduled', new BoundedCount(1, false), 1), $this->transport('scheduled'));
    }

    public function testAMessageThatIsNotDueYetIsNotReady(): void
    {
        MessengerRows::add($this->connection, 'scheduled', $this->at('+1 second'), $this->at('-29 minutes'));
        MessengerRows::add($this->connection, 'scheduled', $this->at('+25 minutes'));

        self::assertEquals(new TransportBacklog('scheduled', new BoundedCount(0, false), null), $this->transport('scheduled'));
    }

    public function testAMessageDueExactlyNowIsReadyWithAgeZero(): void
    {
        MessengerRows::add($this->connection, 'promotions', $this->at('now'));

        self::assertEquals(new TransportBacklog('promotions', new BoundedCount(1, false), 0), $this->transport('promotions'));
    }

    public function testAMessageHandedToAWorkerIsNotReady(): void
    {
        MessengerRows::add($this->connection, 'payment_simulator', $this->at('-1 hour'), deliveredAt: $this->at('-1 minute'));
        MessengerRows::add($this->connection, 'payment_simulator', $this->at('-30 seconds'));

        self::assertEquals(new TransportBacklog('payment_simulator', new BoundedCount(1, false), 30), $this->transport('payment_simulator'));
    }

    public function testAMessageHandedToAWorkerLongAgoIsReadyAgainAndItsAgeCountsFromWhenItWasDue(): void
    {
        // The worker took it an hour ago and died before the acknowledgement: Messenger hands it out again once
        // `redeliver_timeout` has passed, so it waits for a worker, however long ago it was taken.
        MessengerRows::add($this->connection, 'jobs', $this->at('-2 hours'), deliveredAt: $this->at('-1 hour'));

        self::assertEquals(new TransportBacklog('jobs', new BoundedCount(1, false), 7200), $this->transport('jobs'));
    }

    public function testAMessageHandedToAWorkerJustBeforeTheTimeoutIsNotReady(): void
    {
        MessengerRows::add($this->connection, 'jobs', $this->at('-2 hours'), deliveredAt: $this->at('-'.(self::REDELIVER_TIMEOUT - 1).' seconds'));

        self::assertEquals(new TransportBacklog('jobs', new BoundedCount(0, false), null), $this->transport('jobs'));
    }

    public function testAMessageHandedToAWorkerExactlyTheTimeoutAgoIsNotReadyYetAsInDoctrine(): void
    {
        // Doctrine takes back a message with `delivered_at < now - redeliver_timeout`: strictly older.
        MessengerRows::add($this->connection, 'jobs', $this->at('-2 hours'), deliveredAt: $this->at('-'.self::REDELIVER_TIMEOUT.' seconds'));
        self::assertEquals(new TransportBacklog('jobs', new BoundedCount(0, false), null), $this->transport('jobs'));

        MessengerRows::clear($this->connection);
        MessengerRows::add($this->connection, 'jobs', $this->at('-2 hours'), deliveredAt: $this->at('-'.(self::REDELIVER_TIMEOUT + 1).' seconds'));
        self::assertEquals(new TransportBacklog('jobs', new BoundedCount(1, false), 7200), $this->transport('jobs'));
    }

    public function testAMessageHandedToAWorkerThatIsNotDueIsNotReadyWhateverTheDeliveryTime(): void
    {
        MessengerRows::add($this->connection, 'scheduled', $this->at('+10 minutes'), deliveredAt: $this->at('-1 hour'));

        self::assertEquals(new TransportBacklog('scheduled', new BoundedCount(0, false), null), $this->transport('scheduled'));
    }

    public function testTheRedeliveryTimeoutIsTheOneOfTheTransport(): void
    {
        $timeouts = ['outbox' => 300, 'scheduled' => 300, 'payment_simulator' => 300, 'promotions' => 300, 'jobs' => 900];
        // Taken 10 minutes ago: back at a worker of `outbox` (300 s), still with one of `jobs` (900 s).
        MessengerRows::add($this->connection, 'outbox', $this->at('-1 hour'), deliveredAt: $this->at('-10 minutes'));
        MessengerRows::add($this->connection, 'jobs', $this->at('-1 hour'), deliveredAt: $this->at('-10 minutes'));

        $transports = $this->adapter($this->connection, $timeouts)->databaseQueues()->transports;

        self::assertEquals(new TransportBacklog('outbox', new BoundedCount(1, false), 3600), $transports[0]);
        self::assertEquals(new TransportBacklog('jobs', new BoundedCount(0, false), null), $transports[4]);
    }

    public function testATransportWithoutARedeliveryTimeoutIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"jobs"');

        $this->adapter($this->connection, ['outbox' => 300, 'scheduled' => 300, 'payment_simulator' => 300, 'promotions' => 300]);
    }

    public function testEveryQueueCountsOnlyItsOwnMessages(): void
    {
        MessengerRows::add($this->connection, 'outbox', $this->at('-5 seconds'), times: 2);
        MessengerRows::add($this->connection, 'jobs', $this->at('-50 seconds'));
        MessengerRows::add($this->connection, 'failed', $this->at('-1 hour'));
        MessengerRows::add($this->connection, 'events', $this->at('-1 hour'));

        $transports = $this->adapter($this->connection)->databaseQueues()->transports;

        self::assertEquals([
            new TransportBacklog('outbox', new BoundedCount(2, false), 5),
            new TransportBacklog('scheduled', new BoundedCount(0, false), null),
            new TransportBacklog('payment_simulator', new BoundedCount(0, false), null),
            new TransportBacklog('promotions', new BoundedCount(0, false), null),
            new TransportBacklog('jobs', new BoundedCount(1, false), 50),
        ], $transports);
    }

    public function testReadyMessagesAreCountedUpTo1000AndTheAgeIsStillTheOldest(): void
    {
        MessengerRows::add($this->connection, 'jobs', $this->at('-10 seconds'), times: 1000);
        MessengerRows::add($this->connection, 'jobs', $this->at('-700 seconds'));
        MessengerRows::add($this->connection, 'jobs', $this->at('-1 second'), times: 5);

        self::assertEquals(new TransportBacklog('jobs', new BoundedCount(1000, true), 700), $this->transport('jobs'));
    }

    /**
     * The queries of a queue of 100 000 messages among 5 queues read the index (queue_name, available_at,
     * delivered_at, id), not the table: planned as the planner chooses on its own statistics, after ANALYZE.
     * The messages are in a temporary table that takes the place of the real one for this connection.
     */
    public function testNoQueryReadsTheWholeTable(): void
    {
        $this->connection->executeStatement('CREATE TEMP TABLE messenger_messages (LIKE public.messenger_messages INCLUDING ALL)');
        $this->connection->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             SELECT 'x', '{}', (ARRAY['outbox', 'scheduled', 'payment_simulator', 'promotions', 'jobs'])[1 + n % 5],
                    '2026-11-20 11:00:00', timestamp '2026-11-20 11:00:00' + (n % 7000) * interval '1 second',
                    CASE WHEN n % 50 = 0 THEN timestamp '2026-11-20 11:59:00' END
             FROM generate_series(1, 100000) AS n",
        );
        $this->connection->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('x', '{}', 'failed', '2026-11-20 11:00:00', '2026-11-20 11:00:00')");
        $this->connection->executeStatement('ANALYZE messenger_messages');
        $recording = RecordingConnection::like($this->connection);
        $adapter = $this->adapter($recording);

        $queues = $adapter->databaseQueues();

        self::assertSame(1000, $queues->transports[0]->ready->value);
        self::assertTrue($queues->transports[0]->ready->capped);
        $plans = [];
        foreach ($recording->queries as $index => [$sql]) {
            $recorded = RecordingConnection::like($this->connection);
            $recorded->queries = [$recording->queries[$index]];
            $plan = $recorded->planOfTheLastQuery($this->connection, false);
            $plans[] = $plan;
            self::assertDoesNotMatchRegularExpression('/Seq Scan on messenger_messages/', $plan, $sql."\n".$plan);
            self::assertMatchesRegularExpression('/Index (Only )?Scan|Bitmap Index Scan/', $plan, $sql."\n".$plan);
            // The copy of the index (queue_name, available_at, delivered_at, id) of the table, not its primary key.
            self::assertStringContainsString('queue_name_available_at_delivered_at_id_idx', $plan, $sql."\n".$plan);
        }
        self::assertCount(1 + 1 + \count(MessagingStatisticsAdapter::TRANSPORTS), $plans, 'one query for the count and one for the oldest of failed, one per queue');
    }

    private function transport(string $name): TransportBacklog
    {
        foreach ($this->adapter($this->connection)->databaseQueues()->transports as $transport) {
            if ($name === $transport->name) {
                return $transport;
            }
        }

        self::fail('No such queue: '.$name);
    }

    private function at(string $modifier): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->modify($modifier);
    }

    /**
     * @param array<string, int>|null $timeouts the redeliver timeouts; those of the configuration when null
     */
    private function adapter(Connection $connection, ?array $timeouts = null): MessagingStatisticsAdapter
    {
        $client = new MockHttpClient(function (): never {
            ++$this->httpRequests;
            self::fail('The broker must not be called.');
        });

        return new MessagingStatisticsAdapter($connection, $client, 'http://rabbitmq.invalid:15672', 'user', 'secret', 'test', new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))), $timeouts ?? MessengerRows::redeliverTimeouts(self::getContainer()));
    }
}
