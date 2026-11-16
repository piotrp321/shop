<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * What the rebuild of a batch of customer summaries wrote: the number of customers and of their orders.
 */
final readonly class CustomerRebuildTotals
{
    public function __construct(public int $customers, public int $orders)
    {
    }
}
