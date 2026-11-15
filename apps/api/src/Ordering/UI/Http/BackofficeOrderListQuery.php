<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\ListBackofficeOrders;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query string of GET /api/v1/admin/orders. The violation messages never contain the submitted value.
 */
final readonly class BackofficeOrderListQuery
{
    /** The values of OrderStatus. */
    public const array STATUSES = ['new', 'paid', 'shipped', 'delivered', 'cancelled', 'returned'];
    /** The values of BackofficeOrderSort that are keys of the `sort` parameter (the due date of the refunds is not one). */
    public const array SORTS = ['-placedAt', 'placedAt', '-total', 'total'];
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
        #[Assert\Choice(choices: self::SORTS, message: 'The sort must be one of -placedAt, placedAt, -total, total.')]
        public ?string $sort = null,
        #[Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: ListBackofficeOrders::MAX_PER_PAGE)]
        public int $perPage = ListBackofficeOrders::DEFAULT_PER_PAGE,
    ) {
    }

    /**
     * The combinations of the sort that no index serves, or that have no meaning: the pending refunds have their own
     * order, and the amount takes no search term. The violation is on `sort`, the field to change.
     */
    #[Assert\Callback]
    public function validateSortWithFilters(ExecutionContextInterface $context): void
    {
        if (null === $this->sort || !\in_array($this->sort, self::SORTS, true)) {
            return;
        }
        if (null !== $this->refund) {
            $context->buildViolation('The pending refunds have their own order, so they take no sort.')->atPath('sort')->addViolation();
        } elseif (\in_array($this->sort, ['-total', 'total'], true) && null !== $this->q) {
            $context->buildViolation('Sorting by the amount cannot be combined with the search.')->atPath('sort')->addViolation();
        }
    }
}
