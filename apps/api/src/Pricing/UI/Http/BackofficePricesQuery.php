<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\ListBackofficePrices;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query string of GET /api/v1/admin/prices. The violation messages never contain the submitted value.
 */
final readonly class BackofficePricesQuery
{
    /** Longer than any cursor the API hands out (a name of 200 characters in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;
    /** The values of ProductSort of Catalog, which the list pages through. */
    public const array SORTS = ['name', '-name', '-updatedAt', 'updatedAt'];

    public const string BAD_SORT = 'Sortowanie może mieć tylko wartość name, -name, -updatedAt albo updatedAt.';
    public const string BAD_PRODUCT_ID = 'Identyfikator produktu musi być poprawnym UUID.';
    public const string PRODUCT_EXCLUDES = 'Filtr produktu nie łączy się z wyszukiwaniem, kategorią, sortowaniem ani kursorem.';

    public function __construct(
        #[Assert\Length(min: 2, max: 100)]
        // PostgreSQL cannot take a NUL in a parameter: the driver would cut the term there and search for something else.
        #[Assert\Regex(pattern: '/\0/', message: 'The search term must not contain a NUL character.', match: false)]
        public ?string $q = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Uuid]
        public ?string $category = null,
        // A string checked by validateSort: a value of another kind (`sort[]=…`) gets the same Polish message.
        #[OA\Property(type: 'string', enum: self::SORTS, nullable: true)]
        public mixed $sort = null,
        #[Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: ListBackofficePrices::MAX_PER_PAGE)]
        public int $perPage = ListBackofficePrices::DEFAULT_PER_PAGE,
        // A UUID checked by validateProductId: a value of another kind (`productId[]=…`) gets the same Polish message.
        #[OA\Property(type: 'string', nullable: true)]
        public mixed $productId = null,
    ) {
    }

    /**
     * The sort is one of SORTS; a value of another kind (`sort[]=…`) is the same violation.
     */
    #[Assert\Callback]
    public function validateSort(ExecutionContextInterface $context): void
    {
        if (null !== $this->sort && !\in_array($this->sort, self::SORTS, true)) {
            $context->buildViolation(self::BAD_SORT)->atPath('sort')->addViolation();
        }
    }

    /**
     * The product is a UUID: not empty, and a text.
     */
    #[Assert\Callback]
    public function validateProductId(ExecutionContextInterface $context): void
    {
        if (null === $this->productId) {
            return;
        }
        if (!\is_string($this->productId) || '' === $this->productId) {
            $context->buildViolation(self::BAD_PRODUCT_ID)->atPath('productId')->addViolation();

            return;
        }
        $context->getValidator()->inContext($context)->atPath('productId')->validate($this->productId, new Assert\Uuid(message: self::BAD_PRODUCT_ID));
    }

    /**
     * One product is not searched, filtered, sorted or paged: the violation is on each of the fields to drop.
     */
    #[Assert\Callback]
    public function validateProductWithFilters(ExecutionContextInterface $context): void
    {
        if (null === $this->productId) {
            return;
        }
        foreach (['q' => $this->q, 'category' => $this->category, 'sort' => $this->sort, 'after' => $this->after] as $path => $value) {
            if (null !== $value) {
                $context->buildViolation(self::PRODUCT_EXCLUDES)->atPath($path)->addViolation();
            }
        }
    }
}
