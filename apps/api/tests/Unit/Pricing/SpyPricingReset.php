<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\PricingReset;

final class SpyPricingReset implements PricingReset
{
    public int $resets = 0;

    public function reset(): void
    {
        ++$this->resets;
    }
}
