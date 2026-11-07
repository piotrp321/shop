<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

final readonly class CustomerOrderList
{
    /**
     * @param list<CustomerOrderListItem> $items
     * @param string|null                 $nextCursor the `after` of the next page, null on the last one
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
