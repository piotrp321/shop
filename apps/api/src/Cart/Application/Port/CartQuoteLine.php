<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\VariantId;

final readonly class CartQuoteLine
{
    public function __construct(
        public VariantId $variantId,
        public int $quantity,
    ) {
    }
}
