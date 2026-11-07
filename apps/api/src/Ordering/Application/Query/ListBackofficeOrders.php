<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Domain\OrderStatus;

/**
 * One page of the order list of the panel: newest first, or the pending refunds nearest due date first. The search
 * term is an exact order number (`R-100001`, any letter case) or an exact email (any letter case); anything else
 * finds nothing.
 */
final readonly class ListBackofficeOrders
{
    public const int DEFAULT_PER_PAGE = 25;
    public const int MAX_PER_PAGE = 100;
    /** The longest search term: the longest email an order can have. */
    public const int MAX_QUERY_LENGTH = 254;

    /**
     * @param bool        $pendingRefunds only the orders with a refund to do, sorted by its due date
     * @param string|null $q              1 to 254 characters once trimmed
     * @param string|null $after          the `nextCursor` of the previous page of the same sort
     * @param int         $perPage        1 to 100
     */
    public function __construct(
        public ?OrderStatus $status,
        public bool $pendingRefunds,
        public ?string $q,
        public ?string $after,
        public int $perPage,
    ) {
    }
}
