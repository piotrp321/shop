<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Exception\Conflict;

/**
 * A correction based on a version of the stock other than the stored one: the staff member saw a stale stock.
 */
final class StockModifiedConcurrently extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('The stock was modified concurrently. Reload it and try again.');
    }
}
