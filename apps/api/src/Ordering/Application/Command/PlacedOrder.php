<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\View\OrderView;

/**
 * The order a placement ended with; replayed when it is the order of an earlier request with the same key and the
 * same fingerprint.
 */
final readonly class PlacedOrder
{
    public function __construct(
        public OrderView $order,
        public bool $replayed,
    ) {
    }
}
