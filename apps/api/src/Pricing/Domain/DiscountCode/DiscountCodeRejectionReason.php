<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * Why a code gives no discount (decision D6), in the order they are checked.
 */
enum DiscountCodeRejectionReason: string
{
    /** Unknown, disabled, not started yet or expired: one message for all of them. */
    case Invalid = 'invalid';
    case UsedUp = 'used_up';
    case BelowMinimum = 'below_minimum';
}
