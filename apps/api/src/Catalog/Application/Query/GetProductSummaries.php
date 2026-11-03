<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The name and main image (in the `listing` size) of 1 to 100 products, read from the Catalog tables (never from
 * the search index), e.g. for the lines of a cart.
 */
final readonly class GetProductSummaries
{
    public const int MAX_PRODUCTS = 100;

    /**
     * @param list<string> $productIds product UUIDs
     */
    public function __construct(public array $productIds)
    {
    }
}
