<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\StockItem;

/**
 * The stock of a product as stored after a command: physical, reserved, available (physical − reserved), the
 * availability level (out_of_stock, low_stock, in_stock), the version to base a correction on and the time of the
 * last change. A product without a stored stock has zeros, no version and no time.
 */
final readonly class StockView
{
    public function __construct(
        public string $productId,
        public int $physical,
        public int $reserved,
        public int $available,
        public string $level,
        public ?int $version,
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function of(StockItem $item): self
    {
        // Version 0: no change of the stock was ever saved, so it is not stored.
        $stored = 0 !== $item->version();

        return new self(
            $item->id()->toString(),
            $item->physical(),
            $item->reserved(),
            $item->available(),
            AvailabilityLevel::of($item->available())->value,
            $stored ? $item->version() : null,
            $stored ? $item->updatedAt() : null,
        );
    }
}
