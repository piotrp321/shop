<?php

declare(strict_types=1);

namespace App\Delivery\Application\Port;

/**
 * Removes every delivery method, for delivery:seed-demo. Placed orders keep their copies of the methods.
 */
interface DeliveryReset
{
    public function reset(): void;
}
