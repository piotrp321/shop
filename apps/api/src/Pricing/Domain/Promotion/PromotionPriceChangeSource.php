<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

enum PromotionPriceChangeSource: string
{
    /** A boundary of a promotion period was reached, or the end of an active promotion moved. */
    case Promotion = 'promotion';
    /** The demo data (`pricing:seed-demo`): `make seed` rebuilds the index and purges the store's cache anyway. */
    case Seed = 'seed';
}
