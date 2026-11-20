<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Application;

use App\Backoffice\Application\Query\DatabaseQueues;
use App\Backoffice\Application\Query\FailedMessages;
use App\Backoffice\Application\Query\MessagingHealth;
use App\Backoffice\Application\Query\MessagingSection;
use App\Backoffice\Application\Query\TransportBacklog;
use App\Backoffice\Application\Query\TransportStatus;
use App\Shared\Domain\BoundedCount;
use PHPUnit\Framework\TestCase;

/**
 * The one place that decides whether a queue of the database stands and whether the messaging is healthy (spec:
 * docs/features/async-reliability/spec.md, W7): the dashboard and app:messaging:check both ask it.
 */
final class MessagingHealthTest extends TestCase
{
    private const int LIMIT = 600;

    public function testWithNoFailedMessagesAndNothingReadyEverythingIsHealthy(): void
    {
        $section = $this->check(new FailedMessages(new BoundedCount(0, false), null), [
            new TransportBacklog('outbox', new BoundedCount(0, false), null),
            new TransportBacklog('scheduled', new BoundedCount(0, false), null),
        ]);

        self::assertTrue($section->healthy);
        self::assertEquals([
            new TransportStatus('outbox', new BoundedCount(0, false), null, false),
            new TransportStatus('scheduled', new BoundedCount(0, false), null, false),
        ], $section->transports);
    }

    public function testTheTransportsKeepTheOrderOfThePort(): void
    {
        $names = ['outbox', 'scheduled', 'payment_simulator', 'promotions', 'jobs'];

        $section = $this->check(new FailedMessages(new BoundedCount(0, false), null), array_map(
            static fn (string $name): TransportBacklog => new TransportBacklog($name, new BoundedCount(0, false), null),
            $names,
        ));

        self::assertSame($names, array_map(static fn (TransportStatus $transport): string => $transport->name, $section->transports));
    }

    public function testAQueueStandsOnlyWhenItsOldestReadyMessageIsOlderThanTheLimit(): void
    {
        foreach ([[0, false], [self::LIMIT - 1, false], [self::LIMIT, false], [self::LIMIT + 1, true], [86_400, true]] as [$age, $stalled]) {
            $section = $this->check(new FailedMessages(new BoundedCount(0, false), null), [new TransportBacklog('jobs', new BoundedCount(3, false), $age)]);

            self::assertSame($stalled, $section->transports[0]->stalled, "age $age");
            self::assertSame(!$stalled, $section->healthy, "age $age");
        }
    }

    public function testAQueueWithNothingReadyDoesNotStand(): void
    {
        $section = $this->check(new FailedMessages(new BoundedCount(0, false), null), [new TransportBacklog('jobs', new BoundedCount(0, false), null)]);

        self::assertFalse($section->transports[0]->stalled);
    }

    public function testOneFailedMessageMakesItUnhealthyWithoutStoppingAnyQueue(): void
    {
        $oldest = new \DateTimeImmutable('2026-11-20T10:00:00+00:00');

        $section = $this->check(new FailedMessages(new BoundedCount(1, false), $oldest), [new TransportBacklog('outbox', new BoundedCount(2, false), 5)]);

        self::assertFalse($section->healthy);
        self::assertSame($oldest, $section->failed->oldestAt);
        self::assertFalse($section->transports[0]->stalled);
    }

    public function testTheCountsAndTheirCapsArePassedThrough(): void
    {
        $section = $this->check(new FailedMessages(new BoundedCount(1000, true), new \DateTimeImmutable('2026-11-20T10:00:00+00:00')), [
            new TransportBacklog('outbox', new BoundedCount(1000, true), 700),
        ]);

        self::assertEquals(new BoundedCount(1000, true), $section->failed->count);
        self::assertEquals(new TransportStatus('outbox', new BoundedCount(1000, true), 700, true), $section->transports[0]);
    }

    public function testTheLimitIsTheOneItWasBuiltWith(): void
    {
        $queues = new FakeMessagingStatistics(0, [], new DatabaseQueues(new FailedMessages(new BoundedCount(0, false), null), [new TransportBacklog('jobs', new BoundedCount(1, false), 30)]));

        self::assertFalse((new MessagingHealth($queues, 30))->check()->transports[0]->stalled);
        self::assertTrue((new MessagingHealth($queues, 29))->check()->transports[0]->stalled);
    }

    /**
     * @param list<TransportBacklog> $transports
     */
    private function check(FailedMessages $failed, array $transports): MessagingSection
    {
        $statistics = new FakeMessagingStatistics(0, [], new DatabaseQueues($failed, $transports));

        return (new MessagingHealth($statistics, self::LIMIT))->check();
    }
}
