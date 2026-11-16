<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * One page of the customer list of the panel: the newest last order first by default, or in another order of
 * {@see BackofficeCustomerSort}; the search term is a fragment of the email or of the name, only with the default sort.
 */
final readonly class ListBackofficeCustomers
{
    public const int DEFAULT_PER_PAGE = 25;
    public const int MAX_PER_PAGE = 100;

    /**
     * @param string|null $q       3 to 100 characters once trimmed, see {@see BackofficeCustomerSearchTerm}
     * @param string|null $after   the `nextCursor` of the previous page of the same sort
     * @param int         $perPage 1 to 100
     */
    public function __construct(
        public ?string $q,
        public ?BackofficeCustomerSort $sort,
        public ?string $after,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }
}
