<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;

/**
 * The first step of every command of staff: the lock of the order (before anything of Inventory), then the version
 * the staff member saw compared with the one read under the lock. A repeated change with the same version is
 * therefore a conflict too.
 */
final class StaffOrderLock
{
    /**
     * @throws OrderNotFound             also for an id that is no UUID
     * @throws OrderModifiedConcurrently
     */
    public static function lock(OrderRepository $orders, string $orderId, int $expectedVersion): Order
    {
        try {
            $id = OrderId::fromString($orderId);
        } catch (\InvalidArgumentException) {
            throw new OrderNotFound();
        }
        $order = $orders->lock($id);
        if ($order->version() !== $expectedVersion) {
            throw new OrderModifiedConcurrently();
        }

        return $order;
    }
}
