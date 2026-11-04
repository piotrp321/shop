<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Tax\TaxClass;

final readonly class CatalogVariant
{
    /**
     * @param string   $key      `<product slug>/<label slug>` (`<product slug>/standard` for the default variant), matched
     *                           by the demo price patterns
     * @param TaxClass $taxClass the class of the product of the variant
     */
    public function __construct(
        public VariantId $id,
        public string $key,
        public TaxClass $taxClass,
    ) {
    }
}
