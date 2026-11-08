<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Promotion\LowestPriorPrice;
use App\Pricing\Domain\Promotion\PriorPrice;
use App\Pricing\Domain\Promotion\PriorPromotion;

/**
 * What the Omnibus price of variants in promotions is computed from: their price history and their other promotions in
 * the window of each variant, W = [start − 30 days, start) ({@see LowestPriorPrice::windowStart()}), the start being
 * the start of the variant's active promotion. Each method is one read for all the variants.
 */
interface OmnibusHistory
{
    /**
     * @param array<string, \DateTimeImmutable> $promotionStarts the start of the promotion by variant id
     *
     * @return array<string, list<PriorPrice>> by variant id: the entries changed in W and the last one before W, at
     *                                         any VAT rate, oldest first (of one moment, the later change last); a
     *                                         variant without one is absent
     */
    public function prices(array $promotionStarts): array;

    /**
     * @param array<string, \DateTimeImmutable> $promotionStarts the start of the promotion by variant id
     *
     * @return array<string, list<PriorPromotion>> by variant id: the promotions of the variant whose period intersects
     *                                             W (so never the one starting at the start); a variant without one is
     *                                             absent
     */
    public function promotions(array $promotionStarts): array;
}
