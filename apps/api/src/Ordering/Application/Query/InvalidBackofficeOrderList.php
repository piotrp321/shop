<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A combination of the sort and the filters of the order list that no index serves, or that has no meaning. The
 * message never contains the submitted values.
 */
final class InvalidBackofficeOrderList extends \DomainException implements InvalidInput
{
    public static function sortWithPendingRefunds(): self
    {
        return new self('The pending refunds have their own order, so they take no sort.');
    }

    public static function totalWithSearch(): self
    {
        return new self('Sorting by the amount cannot be combined with the search.');
    }

    public static function dueDateAsASort(): self
    {
        return new self('The due date of the refunds is no sort of the list.');
    }

    public static function searchTerm(): self
    {
        return new self('The search term must be an order number or from 3 to 100 characters without control characters.');
    }

    public static function placedDay(): self
    {
        return new self('A day of the range must be a date of the form YYYY-MM-DD.');
    }

    public static function invertedRange(): self
    {
        return new self('The range of days ends before it starts.');
    }

    public static function totalWithDateRange(): self
    {
        return new self('Sorting by the amount cannot be combined with the range of days.');
    }

    public static function pendingRefundsWithDateRange(): self
    {
        return new self('The pending refunds cannot be limited to a range of days.');
    }
}
