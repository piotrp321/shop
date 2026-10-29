<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Domain\Event\DomainEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The outbox relay: forwards an event read from the outbox to RabbitMQ. It runs only when consuming `outbox`,
 * so an event consumed from `events` is never relayed again.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'outbox')]
final readonly class RelayDomainEventToBroker
{
    public function __construct(private MessageBusInterface $eventBus)
    {
    }

    public function __invoke(DomainEvent $event): void
    {
        // The stamp overrides the routing to `outbox`. A failed send throws, so the outbox message is retried.
        $this->eventBus->dispatch($event, [new TransportNamesStamp(['events'])]);
    }
}
