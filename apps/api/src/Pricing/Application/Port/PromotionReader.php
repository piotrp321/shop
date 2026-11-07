<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Application\Query\PromotionCursor;
use App\Pricing\Domain\Promotion\PromotionStatus;

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
}
