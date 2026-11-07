<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * A change of the status of an order a staff member makes (OrderStatus::allowedStaffTransitions()).
 */
enum StaffTransition: string
{
    case Ship = 'ship';
    case Deliver = 'deliver';
    case Cancel = 'cancel';
    case Return = 'return';
}
