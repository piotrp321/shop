<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Application\Query\PromotionCursor;
use App\Pricing\Domain\Promotion\PromotionStatus;
use App\Shared\Domain\BoundedCount;

/**
 * The promotions for the list of the panel, newest start first.
 */
interface PromotionReader
{
    /**
     * @param PromotionStatus|null $status only the promotions in this status at now
     *
     * @return list<ListedPromotion> ordered by start descending, then id, after the cursor; at most $limit
     */
    public function page(?PromotionStatus $status, \DateTimeImmutable $now, ?PromotionCursor $after, int $limit): array;

    /**
     * How many promotions are in the status at now, whatever the cursor of a page: counted up to 10 000 (at most
     * 10 001 rows are read), never the whole table.
     *
     * @param PromotionStatus|null $status only the promotions in this status at now
     */
    public function count(?PromotionStatus $status, \DateTimeImmutable $now): BoundedCount;
}
