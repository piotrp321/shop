<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\TrackingNumber;
use App\Ordering\Domain\TrackingNumberNotChangeable;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Adds, replaces or removes the tracking number of a shipped or delivered order (docs/features/delivery/spec.md,
 * W8a): the lock of the order and the version the staff member saw (like a change of the status), the domain, the save.
 * No reservation, no event, no email and no entry of the history.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ChangeTrackingNumberHandler
{
    public function __construct(
        private OrderRepository $orders,
        private Clock $clock,
    ) {
    }

    /**
     * @throws OrderNotFound               also for an id that is no UUID
     * @throws OrderModifiedConcurrently   the version is not the stored one
     * @throws TrackingNumberNotChangeable
     * @throws InvalidOrderInput           the number is of a wrong shape
     */
    public function __invoke(ChangeTrackingNumber $command): BackofficeOrderView
    {
        $order = StaffOrderLock::lock($this->orders, $command->orderId, $command->expectedVersion);

        $order->changeTrackingNumber(TrackingNumber::optional($command->trackingNumber));
        $this->orders->save($order);

        return BackofficeOrderView::of($this->orders->get($order->id()), $this->clock->now());
    }
}
