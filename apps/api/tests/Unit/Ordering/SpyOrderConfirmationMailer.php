<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\OrderConfirmationMailer;
use App\Ordering\Application\View\OrderView;

final class SpyOrderConfirmationMailer implements OrderConfirmationMailer
{
    /** @var list<OrderView> */
    public array $sent = [];

    public ?\Throwable $failure = null;

    /** Runs while a message is being sent, e.g. to let a concurrent handler finish first. */
    public ?\Closure $whileSending = null;

    public function send(OrderView $order): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        if (null !== $this->whileSending) {
            ($this->whileSending)();
        }
        $this->sent[] = $order;
    }

    public function count(): int
    {
        return \count($this->sent);
    }
}
