<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The statuses of the order cycle so far: a new order is paid or, left unpaid past its deadline, cancelled
 * (shipped and delivered come with ORDER-MANAGEMENT).
 */
enum OrderStatus: string
{
    case New = 'new';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
