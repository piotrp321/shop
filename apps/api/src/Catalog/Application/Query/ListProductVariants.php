<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The variant axes and the variants (id and label) of 1 to 100 products, read from the Catalog tables, e.g. for the
 * price and stock lists of the panel and for the product offers of the list.
 */
final readonly class ListProductVariants
{
    public const int MAX_PRODUCTS = 100;

    /**
     * @param list<string> $productIds product UUIDs
     */
    public function __construct(public array $productIds)
    {
    }
}
