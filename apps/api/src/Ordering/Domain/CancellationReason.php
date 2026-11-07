<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Why an order was cancelled: for want of a payment by the deadline, or by a staff member before shipping a paid one.
 */
enum CancellationReason: string
{
    case PaymentTimeout = 'payment_timeout';
    case Staff = 'staff';
}
