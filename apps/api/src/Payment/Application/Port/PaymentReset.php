<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

/**
 * Removes every payment and every handled webhook event (the demo data).
 */
interface PaymentReset
{
    public function reset(): void;
}
