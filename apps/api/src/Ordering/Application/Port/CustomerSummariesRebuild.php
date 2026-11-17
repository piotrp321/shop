<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * Rebuilds the customer summaries from the orders, one batch of customers (by customer id) at a time, correct while
 * orders are placed and paid: a batch creates the missing rows of its customers and locks its rows before it counts
 * their orders, and writes them in the same transaction (spec ADMIN-FEATURES, „Wdrożenie”). A placement or a payment
 * that has not committed when the batch is counted adds itself after the rebuild of the batch (it waits for the lock);
 * one that committed before is counted.
 */
interface CustomerSummariesRebuild
{
    /**
     * The last customer id of the next batch: the first $size customers after $after (exclusive; from the very first
     * without $after) that have orders or a summary, in the order of the customer ids. Null when there is none.
     */
    public function nextBatchEnd(?string $after, int $size): ?string;

    /**
     * Rewrites the summaries of the customers after $after (exclusive) up to $last (inclusive) from their orders, in a
     * transaction of its own: one row per customer with orders, none for a customer without (so the rows are exactly
     * those the placements and payments would have written).
     */
    public function rebuild(?string $after, string $last): CustomerRebuildTotals;
}
