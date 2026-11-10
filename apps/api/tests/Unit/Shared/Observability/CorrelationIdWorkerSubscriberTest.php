<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Observability;

use App\Shared\Infrastructure\Observability\CorrelationId;
use App\Shared\Infrastructure\Observability\CorrelationIdStamp;
use App\Shared\Infrastructure\Observability\CorrelationIdWorkerSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdWorkerSubscriberTest extends TestCase
{
    private const string FROM_THE_STAMP = '0198a1f0-0000-7000-8000-0000000000a1';
    private const string BEFORE = '0198a1f0-0000-7000-8000-0000000000c1';

    private CorrelationId $correlationId;
    private CorrelationIdWorkerSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->correlationId = new CorrelationId();
        $this->subscriber = new CorrelationIdWorkerSubscriber($this->correlationId);
    }

    public function testTheIdentifierIsSetBeforeAnyListenerThatLogsAndRestoredAfterTheWorkerIsDoneWithTheMessage(): void
    {
        $events = CorrelationIdWorkerSubscriber::getSubscribedEvents();

        // The listeners of Messenger that log a failure (retry, failure transport) listen at 100 and -100.
        self::assertGreaterThan(100, $events[WorkerMessageReceivedEvent::class][1]);
        // WorkerRunningEvent comes after the acknowledgement of the message, the listeners of the failure and the
        // "handled successfully" entry of the worker; the failed and handled events come before some of them.
        self::assertArrayHasKey(WorkerRunningEvent::class, $events);
        self::assertArrayNotHasKey(WorkerMessageFailedEvent::class, $events);
    }

    public function testAReceivedMessageSetsTheIdentifierOfItsStamp(): void
    {
        $envelope = new Envelope(new \stdClass(), [new CorrelationIdStamp(self::FROM_THE_STAMP)]);
        $event = new WorkerMessageReceivedEvent($envelope, 'events');

        $this->subscriber->onMessageReceived($event);

        self::assertSame(self::FROM_THE_STAMP, $this->correlationId->current());
        self::assertSame($envelope, $event->getEnvelope());
    }

    public function testAReceivedMessageWithoutAStampGetsANewIdentifierAndTheStampOfIt(): void
    {
        $event = new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'events');

        $this->subscriber->onMessageReceived($event);

        $id = $this->correlationId->current();
        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));
        self::assertSame($id, $event->getEnvelope()->last(CorrelationIdStamp::class)?->id, 'a retry and the failure transport keep it');
    }

    public function testTheIdentifierStaysUntilTheWorkerRunsAgainAndThenThePreviousOneComesBack(): void
    {
        $this->correlationId->set(self::BEFORE);
        $this->subscriber->onMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope(new \stdClass(), [new CorrelationIdStamp(self::FROM_THE_STAMP)]),
            'events',
        ));

        self::assertSame(self::FROM_THE_STAMP, $this->correlationId->current());

        $this->subscriber->onWorkerRunning($this->running(false));

        self::assertSame(self::BEFORE, $this->correlationId->current());
    }

    public function testTheWorkerWithoutAnIdentifierBeforeIsLeftWithoutOne(): void
    {
        $this->subscriber->onMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope(new \stdClass(), [new CorrelationIdStamp(self::FROM_THE_STAMP)]),
            'events',
        ));

        $this->subscriber->onWorkerRunning($this->running(false));

        self::assertNull($this->correlationId->current());
    }

    public function testAnIdleWorkerDoesNotTouchTheIdentifier(): void
    {
        $this->correlationId->set(self::BEFORE);

        $this->subscriber->onWorkerRunning($this->running(true));

        self::assertSame(self::BEFORE, $this->correlationId->current());
    }

    public function testTheSecondRunningEventDoesNotRestoreTwice(): void
    {
        $this->subscriber->onMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope(new \stdClass(), [new CorrelationIdStamp(self::FROM_THE_STAMP)]),
            'events',
        ));
        $this->subscriber->onWorkerRunning($this->running(false));
        $this->correlationId->set(self::BEFORE);

        $this->subscriber->onWorkerRunning($this->running(true));

        self::assertSame(self::BEFORE, $this->correlationId->current());
    }

    private function running(bool $idle): WorkerRunningEvent
    {
        return new WorkerRunningEvent(new Worker([], new MessageBus()), $idle);
    }
}
