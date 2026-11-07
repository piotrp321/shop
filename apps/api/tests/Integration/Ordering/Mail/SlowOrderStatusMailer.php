<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Mail;

use App\Ordering\Application\Port\OrderStatusMailer;
use App\Ordering\Application\View\OrderView;

/**
 * Holds on for half a second after the email went out, before the handler marks it sent.
 */
final readonly class SlowOrderStatusMailer implements OrderStatusMailer
{
    public function __construct(private OrderStatusMailer $inner)
    {
    }

    public function sendPaymentReceived(OrderView $order): void
    {
        $this->inner->sendPaymentReceived($order);
        usleep(500_000);
    }

    public function sendOrderCancelled(OrderView $order): void
    {
        $this->inner->sendOrderCancelled($order);
        usleep(500_000);
    }
}
