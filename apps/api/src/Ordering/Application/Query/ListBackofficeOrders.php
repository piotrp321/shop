<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Domain\OrderStatus;

/**
 * One page of the order list of the panel: newest first by default, in another order of {@see BackofficeOrderSort}, or
 * the pending refunds nearest due date first. The search term is an exact order number (`R-100001`, any letter case)
 * or a fragment of the number, the email or the name ({@see OrderSearchTerm}); the days limit the placement (days of
 * Europe/Warsaw, both ends included).
 */
final readonly class ListBackofficeOrders
{
    public const int DEFAULT_PER_PAGE = 25;
    public const int MAX_PER_PAGE = 100;
    /** The longest search term: the longest email an order can have. */
    public const int MAX_QUERY_LENGTH = 254;

    /**
     * @param bool                     $pendingRefunds only the orders with a refund to do, sorted by its due date
     * @param string|null              $q              3 to 254 characters once trimmed, see {@see OrderSearchTerm}
     * @param string|null              $after          the `nextCursor` of the previous page of the same sort
     * @param int                      $perPage        1 to 100
     * @param BackofficeOrderSort|null $sort           null for newest first; none with the pending refunds, which have their own
     *                                                 order; the amount takes no search term and no range of days
     * @param string|null              $placedFrom     `YYYY-MM-DD`, the first day of the range; not with the pending refunds
     * @param string|null              $placedTo       `YYYY-MM-DD`, the last day of the range
     * @param bool                     $overdueRefunds only the pending refunds past their due date at the moment of the read (a
     *                                                 subset of the pending ones with the same order and cursor); implies $pendingRefunds
     */
    public function __construct(
        public ?OrderStatus $status,
        public bool $pendingRefunds,
        public ?string $q,
        public ?string $after,
        public int $perPage,
        public ?BackofficeOrderSort $sort = null,
        public ?string $placedFrom = null,
        public ?string $placedTo = null,
        public bool $overdueRefunds = false,
    ) {
    }
}
