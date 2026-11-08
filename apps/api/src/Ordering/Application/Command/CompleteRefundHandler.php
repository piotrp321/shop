<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\RefundNotPending;
use App\Ordering\Domain\StaffActor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Marks the refund of an order completed for a staff member in the transaction of command.bus: the lock of the order,
 * its version, the completion of the domain and the save. No event and no history entry: the status stays.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class CompleteRefundHandler
{
    public function __construct(
        private OrderRepository $orders,
        private Clock $clock,
    ) {
    }

    /**
     * @throws OrderNotFound             also for an id that is no UUID
     * @throws OrderModifiedConcurrently the version is not the stored one
     * @throws RefundNotPending
     * @throws InvalidOrderInput         the note breaks its rules
     */
    public function __invoke(CompleteRefund $command): BackofficeOrderView
    {
        $order = StaffOrderLock::lock($this->orders, $command->orderId, $command->expectedVersion);
        $now = $this->clock->now();

        $order->completeRefund(StaffActor::of($command->staffId, $command->staffUsername), $command->note, $now);
        $this->orders->save($order);

        return BackofficeOrderView::of($this->orders->get($order->id()), $now);
    }
}
