<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Who changed the status of an order: the system (a payment, the expiry), the customer (the placement, also a guest)
 * or a staff member.
 */
enum StatusActorType: string
{
    case System = 'system';
    case Customer = 'customer';
    case Staff = 'staff';
}
