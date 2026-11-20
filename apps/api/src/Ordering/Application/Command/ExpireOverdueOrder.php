<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

/**
 * Cancels an overdue unpaid order now, the safety net of ExpireUnpaidOrder run by ordering:expire-overdue
 * (docs/features/async-reliability/spec.md, W5). Synchronous: no route in messenger.yaml, so command.bus handles it in the
 * process that sends it and it never reaches a transport.
 */
final readonly class ExpireOverdueOrder
{
    public function __construct(public string $orderId)
    {
    }
}
