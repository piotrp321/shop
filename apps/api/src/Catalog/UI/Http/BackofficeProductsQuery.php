<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ListBackofficeProducts;
use App\Catalog\Application\Query\SearchBackofficeProducts;
use App\Shared\Domain\Text;
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
    public const string BAD_TERM = 'Szukana fraza zawiera niedozwolone znaki.';
    public const string BAD_STATUS = 'Status produktu musi być jednym z: draft, published.';
    public const string BAD_CURSOR = 'Kursor stronicowania jest nieprawidłowy.';

    public function __construct(
        // The rule of texts (Text): the characters in the phrase as it came (also NUL, which PostgreSQL cannot take in a
        // parameter), then the length of the trimmed phrase.
        #[Assert\Sequentially([
            new Assert\Callback([self::class, 'validateTermCharacters']),
            new Assert\Length(min: SearchBackofficeProducts::MIN_TERM_LENGTH, max: SearchBackofficeProducts::MAX_TERM_LENGTH, normalizer: [Text::class, 'normalize']),
        ])]
        public ?string $q = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Uuid]
        public ?string $category = null,
        #[Assert\Choice(choices: ['draft', 'published'], message: self::BAD_STATUS)]
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
     * The phrase the list searches for: `q` trimmed by the rule of texts, null without `q`.
     */
    public function term(): ?string
    {
        return null === $this->q ? null : Text::normalize($this->q);
    }

    /**
     * A phrase with a forbidden character of the rule of texts is refused; a phrase that is not valid UTF-8 is left to
     * the length check, which names the charset.
     */
    public static function validateTermCharacters(mixed $term, ExecutionContextInterface $context): void
    {
        if (\is_string($term) && mb_check_encoding($term, 'UTF-8') && Text::hasForbiddenCharacters($term)) {
            $context->buildViolation(self::BAD_TERM)->addViolation();
        }
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
