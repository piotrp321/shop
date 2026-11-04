<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * Transitional (until the product offers of the list replace GET /api/v1/availability, task VARIANTS-4): the stock of
 * 1 to 100 products. A product with exactly one variant has the stock of that variant; a product with more variants
 * is out of stock.
 */
final readonly class GetProductStockLevels
{
    public const int MAX_PRODUCTS = 100;

    /**
     * @param list<string> $productIds product UUIDs
     */
    public function __construct(public array $productIds)
    {
    }
}
