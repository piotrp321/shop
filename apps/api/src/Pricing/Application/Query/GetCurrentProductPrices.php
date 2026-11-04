<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * Transitional (until the product offers of the list replace GET /api/v1/prices, task VARIANTS-4): the current gross
 * prices of 1 to 100 products. A product with exactly one variant has the current price of that variant; a product
 * with more variants has none.
 */
final readonly class GetCurrentProductPrices
{
    public const int MAX_PRODUCTS = 100;

    /**
     * @param list<string> $productIds product UUIDs
     */
    public function __construct(public array $productIds)
    {
    }
}
