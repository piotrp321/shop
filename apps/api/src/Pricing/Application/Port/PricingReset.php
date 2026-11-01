<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

/**
 * Removes all prices and their whole history. Only for the demo data (`pricing:seed-demo`) and tests: nothing else
 * ever deletes history.
 */
interface PricingReset
{
    public function reset(): void;
}
