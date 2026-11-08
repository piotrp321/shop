<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Price\VariantId;

/**
 * The promotions of variants active at a moment, read straight from the store (the effective price is computed at
 * read time, decision A2).
 */
interface ActivePromotions
{
    /**
     * One read for all the variants.
     *
     * @param list<VariantId> $variantIds 1 to 4800 distinct ids
     *
     * @return array<string, ActivePromotion> by variant id, only the variants in a promotion active at the moment
     *                                        (start ≤ moment < end); Q4 allows at most one per variant
     */
    public function at(array $variantIds, \DateTimeImmutable $moment): array;
}
