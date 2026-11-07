<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Mail;

use App\Ordering\Application\Port\OrderStatusMailer;
use App\Ordering\Application\View\OrderView;

/**
 * After the email went out, and before the handler marks it sent, waits until $until says so (polled every 10 ms),
 * at most $timeoutSeconds: e.g. until a concurrent delivery of the same event has tried to claim the sending.
 */
final readonly class WaitingOrderStatusMailer implements OrderStatusMailer
{
    /**
     * @param \Closure(): bool $until
     */
    public function __construct(private OrderStatusMailer $inner, private \Closure $until, private float $timeoutSeconds)
    {
    }

    public function sendPaymentReceived(OrderView $order): void
    {
        $this->inner->sendPaymentReceived($order);
        $this->wait();
    }

    public function sendOrderCancelled(OrderView $order): void
    {
        $this->inner->sendOrderCancelled($order);
        $this->wait();
    }

    private function wait(): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (!($this->until)() && microtime(true) < $deadline) {
            usleep(10_000);
        }
    }
}
