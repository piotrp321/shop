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
}
