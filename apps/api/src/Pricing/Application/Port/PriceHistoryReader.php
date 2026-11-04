<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Application\Query\PriceHistoryCursor;
use App\Pricing\Application\Query\PriceHistoryItem;
use App\Pricing\Domain\Price\VariantId;

/**
 * Reads the recorded price changes of a variant. Read-only: history is only ever appended to, through the price.
 */
interface PriceHistoryReader
{
    /**
     * Entries of the variant by change time, newest first, then by id descending (the later of two changes within
     * one second has the greater id), strictly after the cursor position.
     *
     * @param int $limit 1 or more
     *
     * @return list<PriceHistoryItem> at most $limit, empty when the variant has no (more) history
     */
    public function page(VariantId $variantId, ?PriceHistoryCursor $after, int $limit): array;
}
