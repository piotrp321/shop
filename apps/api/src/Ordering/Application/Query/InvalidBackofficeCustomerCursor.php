<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The paging cursor of the customer list is not one that was handed out for its sort. The message never contains the
 * submitted value.
 */
final class InvalidBackofficeCustomerCursor extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('The paging cursor is invalid.');
    }
}
