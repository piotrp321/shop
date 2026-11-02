<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The paging cursor of the stock history is not one that was handed out. The message never contains the submitted value.
 */
final class InvalidStockHistoryCursor extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('The paging cursor is invalid.');
    }
}
