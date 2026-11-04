<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

use App\Cart\Application\Port\ProductImage;

final readonly class CartLineProduct
{
    public function __construct(
        public string $name,
        public ?ProductImage $image,
    ) {
    }
}
