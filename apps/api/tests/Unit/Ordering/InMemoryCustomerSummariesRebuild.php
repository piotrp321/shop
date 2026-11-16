<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\CustomerRebuildTotals;
use App\Ordering\Application\Port\CustomerSummariesRebuild;

/**
 * Customers to rebuild, in memory: the rebuilt batches are recorded in order; a batch ending at a customer may fail.
 * The rebuild only ever asks after a customer it was given, so the positions of the ids are enough.
 */
final class InMemoryCustomerSummariesRebuild implements CustomerSummariesRebuild
{
    /** @var list<array{?string, string}> the rebuilt batches (after, last), in order */
    public array $rebuilt = [];
    /** @var list<int> the sizes asked for */
    public array $sizes = [];

    /** @var list<string> the customer ids, sorted */
    private array $ids;
    /** @var array<string, int> the position of every id in */
    private array $positions;

    /**
     * @param array<string, int> $customers the number of orders by customer id
     */
    public function __construct(private readonly array $customers, private readonly ?string $failingLast = null)
    {
        $ids = array_map(strval(...), array_keys($customers));
        sort($ids, \SORT_STRING);
        $this->ids = $ids;
        $this->positions = array_flip($ids);
    }

    public function nextBatchEnd(?string $after, int $size): ?string
    {
        $this->sizes[] = $size;
        $start = null === $after ? 0 : $this->positions[$after] + 1;
        $remaining = \count($this->ids) - $start;

        return 0 === $remaining ? null : $this->ids[$start + min($size, $remaining) - 1];
    }

    public function rebuild(?string $after, string $last): CustomerRebuildTotals
    {
        if ($last === $this->failingLast) {
            throw new \RuntimeException('the database went away');
        }
        $this->rebuilt[] = [$after, $last];
        $customers = 0;
        $orders = 0;
        for ($position = null === $after ? 0 : $this->positions[$after] + 1; $position <= $this->positions[$last]; ++$position) {
            ++$customers;
            $orders += $this->customers[$this->ids[$position]];
        }

        return new CustomerRebuildTotals($customers, $orders);
    }
}
