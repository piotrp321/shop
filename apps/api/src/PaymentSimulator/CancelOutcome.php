<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * The result of {@see CancelTransaction}: whether the transaction exists (for this provider) and how it ended.
 */
final readonly class CancelOutcome
{
    public function __construct(
        public bool $found,
        public ?CancelResult $result = null,
    ) {
    }
}
