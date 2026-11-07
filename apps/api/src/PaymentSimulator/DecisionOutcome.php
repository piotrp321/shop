<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * The result of {@see DecideTransaction}: whether the transaction exists (for this provider), whether the decision
 * was applied (it is not for a closed or expired transaction) and where the customer goes back to the shop.
 */
final readonly class DecisionOutcome
{
    public function __construct(
        public bool $found,
        public bool $applied,
        public string $returnUrl = '',
    ) {
    }
}
