<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

enum PromotionPriceChangeSource: string
{
    /**
     * A boundary of a promotion period was reached, or the end of an active promotion moved. Also the source of a promotion
     * written by a staff member (`PlanPromotion::byStaff`), which announces its start.
     */
    case Promotion = 'promotion';
    /**
     * The demo data (`pricing:seed-demo`, `PlanPromotion::bySeed`): `make seed` rebuilds the index and purges the store's
     * cache anyway, so the seed does not announce the start of its promotions.
     */
    case Seed = 'seed';
}
