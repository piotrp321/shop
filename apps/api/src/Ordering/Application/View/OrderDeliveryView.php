<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

/**
 * The delivery of an order: the name of the method (a copy of the placement) and its gross cost in grosze.
 */
final readonly class OrderDeliveryView
{
    public function __construct(
        public string $methodName,
        public int $gross,
    ) {
    }
}
