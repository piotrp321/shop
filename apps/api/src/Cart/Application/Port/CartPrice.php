<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\Money;
use App\Cart\Domain\ProductId;

/**
 * The current gross price of a product, as Pricing gives it.
 */
final readonly class CartPrice
{
    public function __construct(
        public ProductId $productId,
        public Money $gross,
    ) {
    }
}
