<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\StockItem;

/**
 * The stock of a variant as stored after a command: physical, reserved, available (physical − reserved), the
 * availability level (out_of_stock, low_stock, in_stock), the version to base a correction on and the time of the
 * last change. A stored stock always has its version (an empty one is at version 0) and time; a variant without a
 * stored stock (see unstored()) has zeros, no version and no time.
 */
final readonly class StockView
{
    public function __construct(
        public string $variantId,
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
        return self::from($item, $item->version(), $item->updatedAt());
    }

    /**
     * The view of a variant that has no stock record: the version and the time are null, not those of the placeholder.
     */
    public static function unstored(StockItem $item): self
    {
        return self::from($item, null, null);
    }

    private static function from(StockItem $item, ?int $version, ?\DateTimeImmutable $updatedAt): self
    {
        return new self(
            $item->id()->toString(),
            $item->physical(),
            $item->reserved(),
            $item->available(),
            AvailabilityLevel::of($item->available())->value,
            $version,
            $updatedAt,
        );
    }
}
