<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Keeps the identifier of a message of the worker (`messenger:consume`, `messenger:failed:retry`) for the whole time
 * the worker is busy with it, not only while the bus runs. The log entries of a failure come after the bus: the
 * listeners of Messenger that send the message for a retry or to `failed` (WorkerMessageFailedEvent) and the worker's
 * "handled successfully" entry. In `prod` those are the entries that get written (Monolog keeps a buffer only on an
 * error), so they must have the identifier too.
 *
 * WorkerMessageReceivedEvent comes before the bus: the identifier is set from the stamp (a message without one gets a
 * new identifier and the stamp of it, so a retry keeps it and the middleware finds it). WorkerRunningEvent is
 * dispatched by the worker after the message is done (handled or failed, acknowledged, listeners run): the previous
 * identifier comes back. Listening to the handled and failed events instead would restore it before some of those
 * entries. The middleware (CorrelationIdMiddleware) restores what it found, which here is this very identifier.
 */
final class CorrelationIdWorkerSubscriber implements EventSubscriberInterface
{
    private bool $busy = false;
    private ?string $previous = null;

    public function __construct(private readonly CorrelationId $correlationId)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before anything of Messenger that could log about the message; they listen at 100 at the most.
            WorkerMessageReceivedEvent::class => ['onMessageReceived', 256],
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->busy = true;
        $this->previous = $this->correlationId->current();

        $stamp = $event->getEnvelope()->last(CorrelationIdStamp::class);
        if (null === $stamp) {
            $event->addStamps(new CorrelationIdStamp($this->correlationId->start()));

            return;
        }

        $this->correlationId->set($stamp->id);
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (!$this->busy) {
            return;
        }

        $this->busy = false;
        $this->correlationId->set($this->previous);
        $this->previous = null;
    }
}
