<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Mail;

use App\Ordering\Application\Port\OrderConfirmationMailer;
use App\Ordering\Application\View\OrderView;

/**
 * Sends through the first mailer while SMTP is "down" (an adapter whose server cannot be reached, so it fails with
 * a real transport exception) and through the second one once it is "up" (the adapter of the container, which
 * delivers to Mailpit).
 */
final class ToggleableOrderConfirmationMailer implements OrderConfirmationMailer
{
    public bool $up = false;
    /** @var int attempts made while down */
    public int $failedAttempts = 0;

    public function __construct(private readonly OrderConfirmationMailer $down, private readonly OrderConfirmationMailer $working)
    {
    }

    public function send(OrderView $order): void
    {
        if ($this->up) {
            $this->working->send($order);

            return;
        }
        ++$this->failedAttempts;
        $this->down->send($order);
    }
}
