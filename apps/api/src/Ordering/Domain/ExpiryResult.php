<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * What Order::expireUnpaid() did.
 */
enum ExpiryResult
{
    /** The unpaid order is cancelled now. */
    case Cancelled;
    /** The order is new, but the deadline and the window after it for the webhooks have not passed yet. */
    case TooEarly;
    /** The order is paid or already cancelled. */
    case NotApplicable;
}
