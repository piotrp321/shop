<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\OrderStatusMailer;
use App\Ordering\Application\View\OrderView;

final class SpyOrderStatusMailer implements OrderStatusMailer
{
    /**
     * @var list<array{string, OrderView}> the kind of email (`payment_received`, `order_cancelled`,
     *                                     `order_cancelled_by_staff`, `shipped`, `return_received`) and its order
     */
    public array $sent = [];

    public ?\Throwable $failure = null;

    /** Runs while a message is being sent, e.g. to let a concurrent handler finish first. */
    public ?\Closure $whileSending = null;

    public function sendPaymentReceived(OrderView $order): void
    {
        $this->send('payment_received', $order);
    }

    public function sendOrderCancelled(OrderView $order): void
    {
        $this->send('order_cancelled', $order);
    }

    public function sendOrderCancelledByStaff(OrderView $order): void
    {
        $this->send('order_cancelled_by_staff', $order);
    }

    public function sendOrderShipped(OrderView $order): void
    {
        $this->send('shipped', $order);
    }

    public function sendReturnReceived(OrderView $order): void
    {
        $this->send('return_received', $order);
    }

    private function send(string $kind, OrderView $order): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        if (null !== $this->whileSending) {
            ($this->whileSending)();
        }
        $this->sent[] = [$kind, $order];
    }
}
