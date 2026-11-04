<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Application\Query\StockHistoryCursor;
use App\Inventory\Application\Query\StockHistoryItem;
use App\Inventory\Domain\Stock\VariantId;

/**
 * Reads the recorded stock changes of a variant. Read-only: history is only ever appended to, through the stock.
 */
interface StockHistoryReader
{
    /**
     * Entries of the variant by time, newest first, then by id descending (the later of two entries within one
     * second has the greater id), strictly after the cursor position.
     *
     * @param int $limit 1 or more
     *
     * @return list<StockHistoryItem> at most $limit, empty when the variant has no (more) history
     */
    public function page(VariantId $variantId, ?StockHistoryCursor $after, int $limit): array;
}
