<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\ListBackofficeOrders;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of GET /api/v1/admin/orders. The violation messages never contain the submitted value.
 */
final readonly class BackofficeOrderListQuery
{
    /** The values of OrderStatus. */
    public const array STATUSES = ['new', 'paid', 'shipped', 'delivered', 'cancelled', 'returned'];
    /** Longer than any cursor the API hands out (a sort, a moment and a UUID in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;

    public function __construct(
        #[Assert\Choice(choices: self::STATUSES, message: 'The status is not one of the statuses of an order.')]
        public ?string $status = null,
        #[Assert\Choice(choices: ['pending'], message: 'The refund filter can only be `pending`.')]
        public ?string $refund = null,
        #[Assert\Length(min: 1, max: ListBackofficeOrders::MAX_QUERY_LENGTH, normalizer: 'trim')]
        // PostgreSQL cannot take a NUL in a parameter: the driver would cut the term there and search for something else.
        #[Assert\Regex(pattern: '/\0/', message: 'The search term must not contain a NUL character.', match: false)]
        public ?string $q = null,
        #[Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: ListBackofficeOrders::MAX_PER_PAGE)]
        public int $perPage = ListBackofficeOrders::DEFAULT_PER_PAGE,
    ) {
    }
}
