<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class VariantIdentity
{
    /**
     * @param string $key `<slug of the product>/<slug of the label>`, `<slug>/standard` for the default variant
     */
    public function __construct(
        public string $variantId,
        public string $key,
        public string $taxClass,
    ) {
    }
}
