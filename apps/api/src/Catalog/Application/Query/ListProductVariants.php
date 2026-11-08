<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The variant axes, the variants (id and label) and the tax class of 1 to 100 products, read from the Catalog tables,
 * e.g. for the product offers of the store and, with drafts, for the price and stock lists of the panel. A draft is
 * left out unless $includeDrafts (decision A3): the store sees it as a product that does not exist.
 */
final readonly class ListProductVariants
{
    public const int MAX_PRODUCTS = 100;

    /**
     * @param list<string> $productIds product UUIDs
     */
    public function __construct(public array $productIds, public bool $includeDrafts = false)
    {
    }
}
