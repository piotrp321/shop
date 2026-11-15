<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\InvalidBackofficeOrderList;
use App\Ordering\Application\Query\ListBackofficeOrders;
use App\Ordering\Application\Query\OrderPlacedRange;
use App\Ordering\Application\Query\OrderSearchTerm;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query string of GET /api/v1/admin/orders. The violation messages never contain the submitted value. `q`, `placedFrom`
 * and `placedTo` are taken as they come (`mixed`) and checked here, so a value of the wrong kind (`q[]=…`) gets the same
 * Polish message as a bad text, not the English one of the serializer.
 */
final readonly class BackofficeOrderListQuery
{
    /** The values of OrderStatus. */
    public const array STATUSES = ['new', 'paid', 'shipped', 'delivered', 'cancelled', 'returned'];
    /** The values of BackofficeOrderSort that are keys of the `sort` parameter (the due date of the refunds is not one). */
    public const array SORTS = ['-placedAt', 'placedAt', '-total', 'total'];
    public const string BAD_TERM = 'Szukana fraza to numer zamówienia albo tekst od 3 do 100 znaków bez znaków sterujących.';
    public const string BAD_DAY = 'Podaj datę w formacie RRRR-MM-DD, od 2000 do 2999 roku.';
    /** Longer than any cursor the API hands out (a sort, a moment and a UUID in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;

    public function __construct(
        #[Assert\Choice(choices: self::STATUSES, message: 'The status is not one of the statuses of an order.')]
        public ?string $status = null,
        #[Assert\Choice(choices: ['pending', 'overdue'], message: 'The refund filter can only be `pending` or `overdue`.')]
        public ?string $refund = null,
        // A string checked by validateSearch: an order number, or 3 to 100 characters without a control character (a NUL
        // among them: the driver would cut the term there and search for something else).
        #[OA\Property(type: 'string', nullable: true, minLength: 3, maxLength: ListBackofficeOrders::MAX_QUERY_LENGTH)]
        public mixed $q = null,
        #[Assert\Choice(choices: self::SORTS, message: 'The sort must be one of -placedAt, placedAt, -total, total.')]
        public ?string $sort = null,
        #[Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: ListBackofficeOrders::MAX_PER_PAGE)]
        public int $perPage = ListBackofficeOrders::DEFAULT_PER_PAGE,
        // `YYYY-MM-DD`, a day of Europe/Warsaw, checked by validateRange.
        #[OA\Property(type: 'string', format: 'date', nullable: true)]
        public mixed $placedFrom = null,
        #[OA\Property(type: 'string', format: 'date', nullable: true)]
        public mixed $placedTo = null,
    ) {
    }

    /**
     * The search term is an order number or a fragment (OrderSearchTerm); the message does not repeat it.
     */
    #[Assert\Callback]
    public function validateSearch(ExecutionContextInterface $context): void
    {
        if (null === $this->q) {
            return;
        }
        try {
            if (!\is_string($this->q)) {
                throw InvalidBackofficeOrderList::searchTerm();
            }
            OrderSearchTerm::parse($this->q);
        } catch (InvalidBackofficeOrderList) {
            $context->buildViolation(self::BAD_TERM)->atPath('q')->addViolation();
        }
    }

    /**
     * Both days are dates, the first not after the last.
     */
    #[Assert\Callback]
    public function validateRange(ExecutionContextInterface $context): void
    {
        $valid = true;
        foreach (['placedFrom' => $this->placedFrom, 'placedTo' => $this->placedTo] as $path => $day) {
            if (null !== $day && !(\is_string($day) && null !== OrderPlacedRange::dayStart($day))) {
                $context->buildViolation(self::BAD_DAY)->atPath($path)->addViolation();
                $valid = false;
            }
        }
        if ($valid && \is_string($this->placedFrom) && \is_string($this->placedTo) && OrderPlacedRange::dayStart($this->placedFrom) > OrderPlacedRange::dayStart($this->placedTo)) {
            $context->buildViolation('Data „Do” nie może być wcześniejsza niż data „Od”.')->atPath('placedTo')->addViolation();
        }
    }

    /**
     * The combinations that no index serves, or that have no meaning: the pending refunds have their own order and no
     * range of days, and the amount takes no search term and no range of days. The violation is on the field to change:
     * `sort` for the amount, `sort` for the pending refunds with a sort, the days for the pending refunds with them.
     */
    #[Assert\Callback]
    public function validateSortWithFilters(ExecutionContextInterface $context): void
    {
        $hasRange = null !== $this->placedFrom || null !== $this->placedTo;
        if (null !== $this->refund && $hasRange) {
            foreach (['placedFrom' => $this->placedFrom, 'placedTo' => $this->placedTo] as $path => $day) {
                if (null !== $day) {
                    $context->buildViolation('Zwroty do wykonania nie łączą się z zakresem dat.')->atPath($path)->addViolation();
                }
            }
        }
        if (null === $this->sort || !\in_array($this->sort, self::SORTS, true)) {
            return;
        }
        if (null !== $this->refund) {
            $context->buildViolation('Zwroty do wykonania mają własną kolejność, więc nie przyjmują sortowania.')->atPath('sort')->addViolation();
        } elseif (\in_array($this->sort, ['-total', 'total'], true) && (null !== $this->q || $hasRange)) {
            $context->buildViolation('Sortowania po kwocie nie da się połączyć z zakresem dat ani z wyszukiwaniem.')->atPath('sort')->addViolation();
        }
    }
}
