<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Port\BackofficeOrderReader;
use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;

/**
 * Answers every page with the given orders (cut to the limit) and records what it was asked for; the filtering and
 * the order are the database's, tested on PostgreSQL.
 */
final class InMemoryBackofficeOrderReader implements BackofficeOrderReader
{
    /** @var list<array{BackofficeOrderFilter, ?BackofficeOrderCursor, int}> */
    public array $pages = [];

    /**
     * @param list<ListedOrder> $orders
     */
    public function __construct(public array $orders = [])
    {
    }

    public function page(BackofficeOrderFilter $filter, ?BackofficeOrderCursor $after, int $limit): array
    {
        $this->pages[] = [$filter, $after, $limit];

        return \array_slice($this->orders, 0, $limit);
    }
}
