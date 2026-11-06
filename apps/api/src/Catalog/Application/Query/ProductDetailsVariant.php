<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ProductDetailsVariant
{
    /**
     * @param list<string> $values one value per axis, in the order of the axes
     */
    public function __construct(
        public string $variantId,
        public string $label,
        public array $values,
    ) {
    }
}
