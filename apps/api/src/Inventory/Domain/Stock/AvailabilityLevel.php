<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

/**
 * What the store shows instead of a quantity. The only place of the rule.
 */
enum AvailabilityLevel: string
{
    /** The most a product may have available and still be "last items". */
    public const int LOW_STOCK_THRESHOLD = 5;

    case OutOfStock = 'out_of_stock';
    case LowStock = 'low_stock';
    case InStock = 'in_stock';

    public static function of(int $available): self
    {
        return match (true) {
            $available < 0 => throw new \InvalidArgumentException('The available quantity cannot be negative.'),
            0 === $available => self::OutOfStock,
            $available <= self::LOW_STOCK_THRESHOLD => self::LowStock,
            default => self::InStock,
        };
    }
}
