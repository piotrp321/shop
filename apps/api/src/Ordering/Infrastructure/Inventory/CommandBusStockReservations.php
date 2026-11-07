<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Inventory;

use App\Inventory\Application\Command\CommitReservation;
use App\Inventory\Application\Command\InsufficientStock;
use App\Inventory\Application\Command\ReleaseReservation;
use App\Inventory\Application\Command\ReserveStock;
use App\Inventory\Application\Command\ReserveStockLine;
use App\Ordering\Application\Port\CartLineToOrder;
use App\Ordering\Application\Port\StockReservations;
use App\Ordering\Domain\InsufficientStockForOrder;
use App\Ordering\Domain\OrderId;
use App\Ordering\Infrastructure\BusFailure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The reservation of an order through Inventory's ReserveStock on command.bus, with the order id as the key,
 * dispatched inside the transaction of PlaceOrder (nested). Inventory reserves all lines or none; its shortage
 * becomes InsufficientStockForOrder, thrown on so the whole placement rolls back. The number of units is not passed
 * on. The release (ReleaseReservation, nested in the transaction of ExpireUnpaidOrder and of a cancellation by staff)
 * and the commit (CommitReservation, nested in the transaction of a shipment) pass every failure on as it is
 * (ReservationNotFound, ReservationStateConflict, a lost connection).
 */
final readonly class CommandBusStockReservations implements StockReservations
{
    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    public function reserve(OrderId $orderId, array $lines): void
    {
        $command = new ReserveStock(
            $orderId->toString(),
            array_map(static fn (CartLineToOrder $line): ReserveStockLine => new ReserveStockLine($line->variantId->toString(), $line->quantity), $lines),
        );
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $cause = BusFailure::cause($failure);
            if ($cause instanceof InsufficientStock) {
                throw new InsufficientStockForOrder();
            }

            throw $cause;
        }
    }

    public function release(OrderId $orderId): void
    {
        try {
            $this->commandBus->dispatch(new ReleaseReservation($orderId->toString()));
        } catch (HandlerFailedException $failure) {
            throw BusFailure::cause($failure);
        }
    }

    public function commit(OrderId $orderId): void
    {
        try {
            $this->commandBus->dispatch(new CommitReservation($orderId->toString()));
        } catch (HandlerFailedException $failure) {
            throw BusFailure::cause($failure);
        }
    }
}
