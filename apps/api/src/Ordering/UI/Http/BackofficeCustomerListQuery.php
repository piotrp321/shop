<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\BackofficeCustomerSearchTerm;
use App\Ordering\Application\Query\BackofficeCustomerSort;
use App\Ordering\Application\Query\InvalidBackofficeCustomerList;
use App\Ordering\Application\Query\ListBackofficeCustomers;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query string of GET /api/v1/admin/customers. Every parameter is taken as it comes (`mixed`) and checked here, so a
 * value of the wrong kind (`q[]=…`, `perPage=many`) gets the same Polish message as a bad value, not the English one
 * of the serializer; the messages never contain the submitted value.
 */
final readonly class BackofficeCustomerListQuery
{
    /** The values of BackofficeCustomerSort. */
    public const array SORTS = ['-lastOrderAt', 'name', '-orderCount', '-paidGross'];
    /** Longer than any cursor the API hands out (a sort, a name or a moment and a customer id in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;
    public const string BAD_TERM = 'Szukana fraza to fragment e-maila albo imienia i nazwiska, od 3 do 100 znaków bez znaków sterujących.';
    public const string BAD_SORT = 'Sortowanie może mieć tylko wartość -lastOrderAt, name, -orderCount albo -paidGross.';
    public const string SEARCH_WITH_SORT = 'Wyszukiwanie łączy się tylko z sortowaniem od ostatniego zamówienia.';
    public const string BAD_PER_PAGE = 'Liczba wyników na stronie to liczba całkowita od 1 do 100.';
    public const string BAD_CURSOR = 'Kursor strony jest nieprawidłowy.';

    public function __construct(
        #[OA\Property(type: 'string', nullable: true, minLength: 3, maxLength: BackofficeCustomerSearchTerm::MAX_LENGTH)]
        public mixed $q = null,
        #[OA\Property(type: 'string', enum: self::SORTS, nullable: true)]
        public mixed $sort = null,
        #[OA\Property(type: 'string', nullable: true, minLength: 1, maxLength: self::MAX_CURSOR_LENGTH)]
        public mixed $after = null,
        #[OA\Property(type: 'integer', minimum: 1, maximum: ListBackofficeCustomers::MAX_PER_PAGE)]
        public mixed $perPage = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $validTerm = true;
        if (null !== $this->q) {
            try {
                BackofficeCustomerSearchTerm::fragment(\is_string($this->q) ? $this->q : throw InvalidBackofficeCustomerList::searchTerm());
            } catch (InvalidBackofficeCustomerList) {
                $context->buildViolation(self::BAD_TERM)->atPath('q')->addViolation();
                $validTerm = false;
            }
        }
        if (null !== $this->sort && !\in_array($this->sort, self::SORTS, true)) {
            $context->buildViolation(self::BAD_SORT)->atPath('sort')->addViolation();
        } elseif ($validTerm && null !== $this->q && null !== $this->sort && BackofficeCustomerSort::LastOrderDescending->value !== $this->sort) {
            $context->buildViolation(self::SEARCH_WITH_SORT)->atPath('sort')->addViolation();
        }
        if (null !== $this->after && !(\is_string($this->after) && '' !== $this->after && \strlen($this->after) <= self::MAX_CURSOR_LENGTH)) {
            $context->buildViolation(self::BAD_CURSOR)->atPath('after')->addViolation();
        }
        if (null !== $this->perPage && null === $this->pageSize()) {
            $context->buildViolation(self::BAD_PER_PAGE)->atPath('perPage')->addViolation();
        }
    }

    /**
     * The page size once validated: the default without the parameter, null for a value that is none.
     */
    public function pageSize(): ?int
    {
        if (null === $this->perPage) {
            return ListBackofficeCustomers::DEFAULT_PER_PAGE;
        }
        $perPage = \is_int($this->perPage) ? $this->perPage : (\is_string($this->perPage) && 1 === preg_match('/^[0-9]{1,3}$/D', $this->perPage) ? (int) $this->perPage : null);

        return null !== $perPage && $perPage >= 1 && $perPage <= ListBackofficeCustomers::MAX_PER_PAGE ? $perPage : null;
    }
}
