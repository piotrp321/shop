<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query string of GET /api/v1/products, without the repeated `option` key (see {@see OptionFilter}). The violation
 * messages never contain the submitted value.
 */
final readonly class ListProductsQuery
{
    public const string SORT_RELEVANCE = 'relevance';
    public const string SORT_NAME = 'name';
    public const string SORT_PRICE_ASC = 'price_asc';
    public const string SORT_PRICE_DESC = 'price_desc';
    public const string AVAILABLE = 'true';

    public function __construct(
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Uuid]
        public ?string $category = null,
        #[Assert\Length(min: 2, max: 100, normalizer: 'trim')]
        public ?string $q = null,
        #[Assert\PositiveOrZero]
        public ?int $priceMin = null,
        #[Assert\PositiveOrZero]
        public ?int $priceMax = null,
        #[Assert\Choice(choices: [self::AVAILABLE])]
        public ?string $available = null,
        #[Assert\Choice(choices: [self::SORT_RELEVANCE, self::SORT_NAME, self::SORT_PRICE_ASC, self::SORT_PRICE_DESC])]
        public ?string $sort = null,
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 48)]
        public int $perPage = 24,
    ) {
    }

    /**
     * The trimmed phrase, null without `q`.
     */
    public function phrase(): ?string
    {
        return null === $this->q ? null : trim($this->q);
    }

    #[Assert\Callback]
    public function validateCombinations(ExecutionContextInterface $context): void
    {
        if (null !== $this->priceMin && null !== $this->priceMax && $this->priceMin > $this->priceMax) {
            $context->buildViolation('The minimum price must not be higher than the maximum price.')->atPath('priceMin')->addViolation();
        }
        if (self::SORT_RELEVANCE === $this->sort && null === $this->q) {
            $context->buildViolation('Sorting by relevance needs a search phrase.')->atPath('sort')->addViolation();
        }
    }
}
