<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\StockReservations;
use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\OrderTransitionNotAllowed;
use App\Ordering\Domain\StaffActor;
use App\Ordering\Domain\StaffTransition;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Changes the status of an order for a staff member in the transaction of command.bus: the lock of the order, its
 * version, the transition of the domain, the reservation (a shipment commits it, a cancellation releases it; Inventory,
 * nested, before the save, so its flush has nothing of Ordering), the order with its history entry and its event in
 * the outbox, together or not at all. Locks are taken in one order (the order, then Inventory), like the expiry, so
 * concurrent changes with the same version run one after another and the later one is a version conflict, never a
 * reservation in an unexpected state. A failure of Inventory (ReservationNotFound, ReservationStateConflict) is
 * a logic error and is thrown as it is.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ChangeOrderStatusHandler
{
    public function __construct(
        private OrderRepository $orders,
        private StockReservations $reservations,
        private DomainEventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * @throws OrderNotFound             also for an id that is no UUID
     * @throws OrderModifiedConcurrently the version is not the stored one
     * @throws OrderTransitionNotAllowed
     * @throws InvalidOrderInput         the note breaks its rules
     */
    public function __invoke(ChangeOrderStatus $command): BackofficeOrderView
    {
        $order = StaffOrderLock::lock($this->orders, $command->orderId, $command->expectedVersion);
        $staff = StaffActor::of($command->staffId, $command->staffUsername);
        $now = $this->clock->now();

        switch ($command->transition) {
            case StaffTransition::Ship:
                $order->ship($staff, $command->note, $now);
                $this->reservations->commit($order->id());
                break;
            case StaffTransition::Deliver:
                $order->deliver($staff, $command->note, $now);
                break;
            case StaffTransition::Cancel:
                $order->cancel($staff, $command->note, $now);
                $this->reservations->release($order->id());
                break;
            case StaffTransition::Return:
                $order->registerReturn($staff, $command->note, $now);
                break;
        }
        $this->orders->save($order);
        $events = $order->releaseEvents();
        if ([] !== $events) {
            $this->events->publish(...$events);
        }

        return BackofficeOrderView::of($this->orders->get($order->id()), $now);
    }
}
