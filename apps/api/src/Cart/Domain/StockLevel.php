<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * The availability level of a variant as Inventory gives it (the threshold stays in Inventory), never a number of
 * units. The order of the cases is the order of preference: in stock is better than low stock.
 */
enum StockLevel: string
{
    case InStock = 'in_stock';
    case LowStock = 'low_stock';
    case OutOfStock = 'out_of_stock';

    public function isBetterThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::InStock => 2,
            self::LowStock => 1,
            self::OutOfStock => 0,
        };
    }
}
