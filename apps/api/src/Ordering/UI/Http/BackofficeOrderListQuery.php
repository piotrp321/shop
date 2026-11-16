<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\InvalidBackofficeOrderList;
use App\Ordering\Application\Query\ListBackofficeOrders;
use App\Ordering\Application\Query\OrderPlacedRange;
use App\Ordering\Application\Query\OrderSearchTerm;
use App\Ordering\Domain\ControlCharacters;
use App\Ordering\Domain\OrderOwner;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query string of GET /api/v1/admin/orders. The violation messages never contain the submitted value. `refund`, `q`,
 * `placedFrom` and `placedTo` are taken as they come (`mixed`) and checked here, so a value of the wrong kind (`q[]=…`)
 * gets the same Polish message as a bad text, not the English one of the serializer.
 */
final readonly class BackofficeOrderListQuery
{
    /** The values of OrderStatus. */
    public const array STATUSES = ['new', 'paid', 'shipped', 'delivered', 'cancelled', 'returned'];
    /** The values of BackofficeOrderSort that are keys of the `sort` parameter (the due date of the refunds is not one). */
    public const array SORTS = ['-placedAt', 'placedAt', '-total', 'total'];
    /** The values of the `refund` parameter. */
    public const array REFUNDS = ['pending', 'overdue'];
    public const string BAD_REFUND = 'Filtr zwrotów może mieć tylko wartość pending albo overdue.';
    public const string BAD_TERM = 'Szukana fraza to numer zamówienia albo tekst od 3 do 100 znaków bez znaków sterujących.';
    public const string BAD_DAY = 'Podaj datę w formacie RRRR-MM-DD, od 2000 do 2999 roku.';
    public const string BAD_CUSTOMER = 'Identyfikator klienta ma od 1 do 255 znaków bez znaków sterujących.';
    public const string CUSTOMER_EXCLUDES = 'Zamówienia klienta łączą się tylko z filtrem statusu: bez wyszukiwania, sortowania, zakresu dat i zwrotów.';
    /** Longer than any cursor the API hands out (a sort, a moment and a UUID in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;

    public function __construct(
        #[Assert\Choice(choices: self::STATUSES, message: 'The status is not one of the statuses of an order.')]
        public ?string $status = null,
        // A string checked by validateRefund.
        #[OA\Property(type: 'string', enum: self::REFUNDS, nullable: true)]
        public mixed $refund = null,
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
        // A string checked by validateCustomer: the `sub` of an account of the store, 1 to 255 characters without a
        // control character.
        #[OA\Property(type: 'string', nullable: true, minLength: 1, maxLength: OrderOwner::MAX_CUSTOMER_ID_LENGTH)]
        public mixed $customerId = null,
    ) {
    }

    /**
     * The refund filter is one of REFUNDS; a value of another kind (`refund[]=…`) is the same violation.
     */
    #[Assert\Callback]
    public function validateRefund(ExecutionContextInterface $context): void
    {
        if (null !== $this->refund && !\in_array($this->refund, self::REFUNDS, true)) {
            $context->buildViolation(self::BAD_REFUND)->atPath('refund')->addViolation();
        }
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
     * The customer is the `sub` of an account; its orders are read on their partial index newest first, so they take
     * the status filter only: the violation is on each of the other filters given.
     */
    #[Assert\Callback]
    public function validateCustomer(ExecutionContextInterface $context): void
    {
        if (null === $this->customerId) {
            return;
        }
        if (!\is_string($this->customerId) || '' === $this->customerId || mb_strlen($this->customerId) > OrderOwner::MAX_CUSTOMER_ID_LENGTH || ControlCharacters::in($this->customerId)) {
            $context->buildViolation(self::BAD_CUSTOMER)->atPath('customerId')->addViolation();
        }
        foreach (['q' => $this->q, 'sort' => $this->sort, 'placedFrom' => $this->placedFrom, 'placedTo' => $this->placedTo, 'refund' => $this->refund] as $path => $value) {
            if (null !== $value) {
                $context->buildViolation(self::CUSTOMER_EXCLUDES)->atPath($path)->addViolation();
            }
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
