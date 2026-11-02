<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * The authoritative stock of 1 to 100 products, read from the Inventory tables in the process, never cached.
 */
final readonly class GetStockLevels
{
    public const int MAX_PRODUCTS = 100;

    /**
     * @param list<string> $productIds product UUIDs
     */
    public function __construct(public array $productIds)
    {
    }
}
