<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ProductForPricing
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $taxClass,
    ) {
    }
}
