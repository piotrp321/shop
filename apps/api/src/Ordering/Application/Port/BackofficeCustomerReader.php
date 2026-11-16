<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Query\BackofficeCustomerCursor;
use App\Ordering\Application\Query\BackofficeCustomerSort;
use App\Ordering\Application\View\ShippingAddressView;
use App\Shared\Domain\BoundedCount;

/**
 * Reads the customer summaries and the addresses of their orders for the panel, without locks. Read-only.
 */
interface BackofficeCustomerReader
{
    /**
     * The customers that match the fragment (of the email or the name, any letter case; none: every customer),
     * strictly after the cursor, in the order of the sort, the customer id ascending breaking the ties.
     *
     * @param string|null                   $fragment 3 to 100 characters, as typed; only with the sort by the last order
     * @param BackofficeCustomerCursor|null $after    of the same sort
     * @param int                           $limit    1 or more
     *
     * @return list<ListedCustomer> at most $limit
     */
    public function page(?string $fragment, BackofficeCustomerSort $sort, ?BackofficeCustomerCursor $after, int $limit): array;

    /**
     * How many customers match the fragment, counted up to $limit (at most $limit + 1 rows are read).
     */
    public function count(?string $fragment, int $limit = 10_000): BoundedCount;

    public function find(string $customerId): ?CustomerSummary;

    /**
     * The distinct shipping addresses of the last $orders orders of the customer, the one used last first, at most
     * $limit of them.
     *
     * @return list<ShippingAddressView>
     */
    public function addresses(string $customerId, int $orders, int $limit): array;
}
