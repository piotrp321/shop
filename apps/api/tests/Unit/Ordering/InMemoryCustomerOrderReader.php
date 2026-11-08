<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\CustomerListedOrder;
use App\Ordering\Application\Port\CustomerOrderReader;
use App\Ordering\Application\Query\BackofficeOrderCursor;

/**
 * Answers every page with the given orders (cut to the limit) and records what it was asked for; the ownership filter
 * and the order are the database's, tested on PostgreSQL.
 */
final class InMemoryCustomerOrderReader implements CustomerOrderReader
{
    /** @var list<array{string, ?BackofficeOrderCursor, int}> */
    public array $pages = [];

    /**
     * @param list<CustomerListedOrder> $orders
     */
    public function __construct(public array $orders = [])
    {
    }

    public function page(string $customerId, ?BackofficeOrderCursor $after, int $limit): array
    {
        $this->pages[] = [$customerId, $after, $limit];

        return \array_slice($this->orders, 0, $limit);
    }
}
