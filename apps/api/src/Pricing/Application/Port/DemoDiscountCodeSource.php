<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Application\Demo\DemoDiscountCodeList;

interface DemoDiscountCodeSource
{
    /**
     * @throws \InvalidArgumentException when the definition is missing or invalid
     */
    public function load(): DemoDiscountCodeList;
}
