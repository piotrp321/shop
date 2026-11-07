<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * The status of the order a payment pays, as Ordering reports it.
 */
enum OrderState: string
{
    case New = 'new';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
