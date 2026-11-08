<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

/**
 * A staff member marks the pending refund of an order they saw at expectedVersion as transferred, with an optional
 * note.
 */
final readonly class CompleteRefund
{
    public function __construct(
        public string $orderId,
        public int $expectedVersion,
        public ?string $note,
        public string $staffId,
        public string $staffUsername,
    ) {
    }
}
