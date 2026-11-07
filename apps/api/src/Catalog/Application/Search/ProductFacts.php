<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * The offer of a product for the search index: the "from" price (in grosze, the lowest price of the purchasable
 * variants, null without one) and the facts of each variant.
 */
final readonly class ProductFacts
{
    /**
     * @param array<string, VariantFacts> $variants by variant id
     */
    public function __construct(
        public ?int $fromGross,
        public array $variants,
    ) {
    }
}
