<?php

declare(strict_types=1);

namespace App\Ordering\Application\ReadModel;

use App\Ordering\Application\Port\CustomerSummariesRebuild;

/**
 * The customer part of ordering:read-models:rebuild: rebuilds every customer with orders or a summary, in batches of
 * 500 by customer id from the first one on, each in a transaction of its own (CustomerSummariesRebuild), so it is
 * correct while orders are placed and paid, may be stopped at any batch and run again from the start. A customer
 * whose first order comes after its batch was rebuilt adds itself, which is right.
 */
final readonly class CustomerRebuild
{
    private const int BATCH_SIZE = 500;
    private const int PROGRESS_EVERY = 100;

    public function __construct(private CustomerSummariesRebuild $customers)
    {
    }

    /**
     * @param \Closure(string): void $report takes a progress line every 100 batches
     *
     * @return string the summary
     *
     * @throws \Throwable when a batch cannot be rebuilt; the batches before it stay rebuilt
     */
    public function run(\Closure $report): string
    {
        $batches = 0;
        $customers = 0;
        $orders = 0;
        $after = null;
        while (null !== ($last = $this->customers->nextBatchEnd($after, self::BATCH_SIZE))) {
            $totals = $this->customers->rebuild($after, $last);
            ++$batches;
            $customers += $totals->customers;
            $orders += $totals->orders;
            if (0 === $batches % self::PROGRESS_EVERY) {
                $report(\sprintf('customers: %d batches rebuilt, %d customers, up to %s', $batches, $customers, $last));
            }
            $after = $last;
        }

        return \sprintf('customers: %d customers rebuilt in %d batches, %d orders', $customers, $batches, $orders);
    }
}
