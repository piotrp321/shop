<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\StockLevel;
use App\Cart\Domain\VariantId;

/**
 * The units of a variant available now and their availability level (Inventory). The units are never shown to
 * a client; the level is.
 */
final readonly class VariantAvailability
{
    public function __construct(
        public VariantId $variantId,
        public int $available,
        public StockLevel $level,
    ) {
    }
}
