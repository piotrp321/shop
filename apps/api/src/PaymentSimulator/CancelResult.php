<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * How a cancellation ended: the transaction is cancelled (also when it already was), or it was closed before by
 * a decision of the customer.
 */
enum CancelResult: string
{
    case Cancelled = 'cancelled';
    case Paid = 'paid';
    case Rejected = 'rejected';
}
