<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\ListCustomerOrders;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of GET /api/v1/me/orders. The violation messages never contain the submitted value.
 */
final readonly class CustomerOrderListQuery
{
    public function __construct(
        #[Assert\Length(min: 1, max: BackofficeOrderListQuery::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: ListCustomerOrders::MAX_PER_PAGE)]
        public int $perPage = ListCustomerOrders::DEFAULT_PER_PAGE,
    ) {
    }
}
