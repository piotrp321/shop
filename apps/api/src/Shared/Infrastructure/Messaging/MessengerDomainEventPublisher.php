<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches domain events on the event bus, which routes them to the outbox: the row is written in the
 * transaction of the command that raised them.
 */
final readonly class MessengerDomainEventPublisher implements DomainEventPublisher
{
    public function __construct(private MessageBusInterface $eventBus)
    {
    }

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->eventBus->dispatch($event);
        }
    }
}
