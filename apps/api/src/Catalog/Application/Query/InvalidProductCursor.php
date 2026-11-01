<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The paging cursor is not one this query issued. The message never contains the submitted value.
 */
final class InvalidProductCursor extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('The paging cursor is invalid.');
    }
}
