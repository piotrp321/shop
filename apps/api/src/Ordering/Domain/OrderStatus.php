<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The statuses of the cycle of an order: a new order is paid or, left unpaid past its deadline, cancelled; staff
 * ship a paid order (or cancel it before shipping), mark it delivered and register its return. The transitions staff
 * may make from each status are listed here only (allowedStaffTransitions()).
 */
enum OrderStatus: string
{
    case New = 'new';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    /**
     * The table of the transitions of staff: the only place of the rule.
     *
     * @return list<StaffTransition>
     */
    public function allowedStaffTransitions(): array
    {
        return match ($this) {
            self::Paid => [StaffTransition::Ship, StaffTransition::Cancel],
            self::Shipped => [StaffTransition::Deliver, StaffTransition::Return],
            self::Delivered => [StaffTransition::Return],
            self::New, self::Cancelled, self::Returned => [],
        };
    }

    public function allowsStaff(StaffTransition $transition): bool
    {
        return \in_array($transition, $this->allowedStaffTransitions(), true);
    }

    /**
     * Whether a payment paid the order and it went on from there without a cancellation: paid, shipped, delivered
     * or returned.
     */
    public function isPaidFor(): bool
    {
        return match ($this) {
            self::Paid, self::Shipped, self::Delivered, self::Returned => true,
            self::New, self::Cancelled => false,
        };
    }
}
