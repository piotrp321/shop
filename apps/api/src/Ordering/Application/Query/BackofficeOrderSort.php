<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The orders of the order list of the panel. The value is the key of the `sort` parameter (a leading `-` is the
 * descending direction) and the sort of a keyset cursor; the id always breaks the ties, in the same direction.
 * {@see self::RefundDueDate} is the order of the pending refunds: the API does not take it as a key, the filter of the
 * pending refunds implies it.
 */
enum BackofficeOrderSort: string
{
    case PlacedDescending = '-placedAt';
    case PlacedAscending = 'placedAt';
    case TotalDescending = '-total';
    case TotalAscending = 'total';
    case RefundDueDate = 'refund';

    public function isDescending(): bool
    {
        return self::PlacedDescending === $this || self::TotalDescending === $this;
    }

    public function byTotal(): bool
    {
        return self::TotalDescending === $this || self::TotalAscending === $this;
    }
}
