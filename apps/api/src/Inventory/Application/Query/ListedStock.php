<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\StockItem;

/**
 * The stored stock of a product in the list: physical, reserved, available (physical − reserved), the availability
 * level (out_of_stock, low_stock, in_stock), the version to base a correction on and the time of the last change.
 */
final readonly class ListedStock
{
    public function __construct(
        public int $physical,
        public int $reserved,
        public int $available,
        public string $level,
        public int $version,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function of(StockItem $item): self
    {
        return new self(
            $item->physical(),
            $item->reserved(),
            $item->available(),
            AvailabilityLevel::of($item->available())->value,
            $item->version(),
            $item->updatedAt(),
        );
    }
}
