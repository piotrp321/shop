<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Application\Demo\DemoPromotionList;

interface DemoPromotionSource
{
    /**
     * @throws \InvalidArgumentException when the definition is missing or invalid
     */
    public function load(): DemoPromotionList;
}
