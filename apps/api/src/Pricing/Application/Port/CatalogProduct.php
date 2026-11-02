<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Tax\TaxClass;

final readonly class CatalogProduct
{
    public function __construct(
        public ProductId $id,
        public string $slug,
        public TaxClass $taxClass,
    ) {
    }
}
