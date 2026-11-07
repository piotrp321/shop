<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Why an order was cancelled: so far only for want of a payment by the deadline.
 */
enum CancellationReason: string
{
    case PaymentTimeout = 'payment_timeout';
}
