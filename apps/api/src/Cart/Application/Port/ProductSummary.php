<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\ProductId;

/**
 * A product Catalog knows: its name and main image (null without images).
 */
final readonly class ProductSummary
{
    public function __construct(
        public ProductId $id,
        public string $name,
        public ?ProductImage $image,
    ) {
    }
}
