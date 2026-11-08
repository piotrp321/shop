<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * One page of the order history of a customer, newest first. The cursor is the one of the order list of the panel
 * (BackofficeOrderCursor, the sort by placement).
 */
final readonly class ListCustomerOrders
{
    public const int DEFAULT_PER_PAGE = 10;
    public const int MAX_PER_PAGE = 50;

    /**
     * @param string      $customerId the `sub` of the token of the customer
     * @param string|null $after      the `nextCursor` of the previous page
     * @param int         $perPage    1 to 50
     */
    public function __construct(
        public string $customerId,
        public ?string $after,
        public int $perPage,
    ) {
    }
}
