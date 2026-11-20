<?php

declare(strict_types=1);

namespace App\Delivery\Application\Port;

use App\Delivery\Application\Demo\DemoDeliveryMethodList;

interface DemoDeliveryMethodSource
{
    /**
     * @throws \InvalidArgumentException when the definition is missing or invalid
     */
    public function load(): DemoDeliveryMethodList;
}
