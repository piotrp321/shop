<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A search term or a combination of the search and the sort of the customer list that is refused. The message never
 * contains the submitted values.
 */
final class InvalidBackofficeCustomerList extends \DomainException implements InvalidInput
{
    public static function searchTerm(): self
    {
        return new self('The search term must be from 3 to 100 characters without control characters.');
    }

    public static function searchWithSort(): self
    {
        return new self('The search is sorted by the last order only.');
    }
}
