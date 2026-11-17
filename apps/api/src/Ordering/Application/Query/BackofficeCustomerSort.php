<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The orders of the customer list of the panel. The value is the key of the `sort` parameter (a leading `-` is the
 * descending direction) and the sort of a keyset cursor; the customer id always breaks the ties, ascending (the
 * indexes are (column DESC, customer_id) and (lower(full_name), customer_id)).
 */
enum BackofficeCustomerSort: string
{
    case LastOrderDescending = '-lastOrderAt';
    case Name = 'name';
    case OrderCountDescending = '-orderCount';
    case PaidGrossDescending = '-paidGross';
}
