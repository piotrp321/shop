<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Messaging;

use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\Port\UnpaidOrderExpiry;
use App\Ordering\Domain\OrderId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Sends ExpireUnpaidOrder on command.bus with a DelayStamp; the routing puts it on the Doctrine transport
 * `scheduled`, on the connection of the caller's transaction, so the row is written or rolled back with the order.
 * The transport stores available_at to the second (rounded up) and compares it with the system time.
 */
final readonly class MessengerUnpaidOrderExpiry implements UnpaidOrderExpiry
{
    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    public function schedule(OrderId $orderId, \DateTimeImmutable $at): void
    {
        $this->commandBus->dispatch(new ExpireUnpaidOrder($orderId->toString()), [DelayStamp::delayUntil($at)]);
    }
}
