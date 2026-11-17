<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\ProductId;
use App\Shared\Domain\BoundedCount;

/**
 * The products that have a variant at a stock level (out of stock or low stock), read from the stock records alone, in
 * ascending order of their ids. A record without its product (not yet synced) belongs to no product.
 */
interface StockLevelProducts
{
    /**
     * @param AvailabilityLevel $level the out-of-stock or the low-stock level
     * @param ProductId|null    $after the last product of the previous page, null for the first page
     * @param int               $limit at least 1
     *
     * @return list<ProductId> at most $limit, ascending
     *
     * @throws \InvalidArgumentException the in-stock level, which is no filter
     */
    public function page(AvailabilityLevel $level, ?ProductId $after, int $limit): array;

    /**
     * How many products are at the level, counted up to 10 000 (at most 10 001 are read), never the whole table.
     *
     * @throws \InvalidArgumentException the in-stock level, which is no filter
     */
    public function count(AvailabilityLevel $level): BoundedCount;

    /**
     * How many stock records (variants) are at the level, counted up to $limit (at most $limit + 1 are read), never
     * the whole table: the queue of the dashboard, which counts variants where the stock list shows their products.
     *
     * @param int $limit 1 or more
     *
     * @throws \InvalidArgumentException the in-stock level, which is no filter
     */
    public function countVariants(AvailabilityLevel $level, int $limit): BoundedCount;
}
