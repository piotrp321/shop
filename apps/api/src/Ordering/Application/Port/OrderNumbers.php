<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\OrderNumber;

/**
 * The next order number. Not part of the transaction: a placement that rolls back leaves a gap.
 */
interface OrderNumbers
{
    public function next(): OrderNumber;
}
