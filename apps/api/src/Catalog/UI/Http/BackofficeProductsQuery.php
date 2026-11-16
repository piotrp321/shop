<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ListBackofficeProducts;
use App\Catalog\Application\Query\SearchBackofficeProducts;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query string of GET /api/v1/admin/products. The violation messages never contain the submitted value.
 */
final readonly class BackofficeProductsQuery
{
    /** Longer than any cursor the API hands out (a name of 200 characters in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;
    /** The values of ProductSort. */
    public const array SORTS = ['name', '-name', '-updatedAt', 'updatedAt'];
    public const string BAD_SORT = 'Sortowanie może mieć tylko wartość name, -name, -updatedAt albo updatedAt.';
    public const string BAD_IMAGES = 'Filtr zdjęć może mieć tylko wartość none.';

    public function __construct(
        #[Assert\Length(min: SearchBackofficeProducts::MIN_TERM_LENGTH, max: SearchBackofficeProducts::MAX_TERM_LENGTH)]
        // PostgreSQL cannot take a NUL in a parameter: the driver would cut the term there and search for something else.
        #[Assert\Regex(pattern: '/\0/', message: 'The search term must not contain a NUL character.', match: false)]
        public ?string $q = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Uuid]
        public ?string $category = null,
        #[Assert\Choice(choices: ['draft', 'published'], message: 'The status must be draft or published.')]
        public ?string $status = null,
        // A string checked by validateSort: a value of another kind (`sort[]=…`) gets the same Polish message.
        #[OA\Property(type: 'string', enum: self::SORTS, nullable: true)]
        public mixed $sort = null,
        #[Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: SearchBackofficeProducts::MAX_PER_PAGE)]
        public int $perPage = ListBackofficeProducts::DEFAULT_PER_PAGE,
        // A string checked by validateImages: a value of another kind (`images[]=…`) gets the same Polish message.
        #[OA\Property(type: 'string', enum: ['none'], nullable: true)]
        public mixed $images = null,
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
     * The images filter is `none`.
     */
    #[Assert\Callback]
    public function validateImages(ExecutionContextInterface $context): void
    {
        if (null !== $this->images && 'none' !== $this->images) {
            $context->buildViolation(self::BAD_IMAGES)->atPath('images')->addViolation();
        }
    }
}
