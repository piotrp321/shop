<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

/**
 * The copy of the promotion that gave the unit price of a line: its name, percent and the catalog unit gross.
 */
final readonly class OrderLinePromotionView
{
    public function __construct(
        public string $name,
        public int $percentOff,
        public int $regularUnitGross,
    ) {
    }
}
