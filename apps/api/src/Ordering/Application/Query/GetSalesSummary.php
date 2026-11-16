<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The sales of the dashboard. The caller decides whether the staff member may see them (VIEW_SALES of this module);
 * the query itself sums the hourly sales, never the orders.
 */
final readonly class GetSalesSummary
{
}
