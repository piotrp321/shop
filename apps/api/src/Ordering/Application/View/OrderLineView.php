<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

final readonly class OrderLineView
{
    /**
     * @param string $variantLabel empty for the default variant
     */
    public function __construct(
        public string $variantId,
        public string $productName,
        public string $variantLabel,
        public int $quantity,
        public int $unitGross,
        public int $lineGross,
    ) {
    }
}
