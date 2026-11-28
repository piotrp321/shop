<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Shared\Domain\Text;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query string of GET /api/v1/products, without the repeated `option` key (see {@see OptionFilter}). The violation
 * messages never contain the submitted value.
 */
final class ListProductsQuery
{
    public const string SORT_RELEVANCE = 'relevance';
    public const string SORT_NAME = 'name';
    public const string SORT_PRICE_ASC = 'price_asc';
    public const string SORT_PRICE_DESC = 'price_desc';
    public const string AVAILABLE = 'true';
    public const string BAD_PHRASE = 'Szukana fraza zawiera niedozwolone znaki.';
    // Strings, not ints: the query-string denormalizer would silently cut "10.5" to 10 (and deprecate doing so).
    private const string GROSZE_PATTERN = '/^\d{1,12}\z/';
    private const string BAD_PRICE = 'Cena musi być liczbą całkowitą groszy, 0 albo więcej.';

    public function __construct(
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Uuid]
        public readonly ?string $category = null,
        // The rule of texts (Text): the characters in the phrase as it came, then the length of the trimmed phrase.
        #[Assert\Sequentially([
            new Assert\Callback([self::class, 'validatePhraseCharacters']),
            new Assert\Length(min: 2, max: 100, normalizer: [Text::class, 'normalize']),
        ])]
        public readonly ?string $q = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Regex(pattern: self::GROSZE_PATTERN, message: self::BAD_PRICE)]
        public readonly ?string $priceMin = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Regex(pattern: self::GROSZE_PATTERN, message: self::BAD_PRICE)]
        public readonly ?string $priceMax = null,
        #[Assert\Choice(choices: [self::AVAILABLE])]
        public readonly ?string $available = null,
        #[Assert\Choice(choices: [self::SORT_RELEVANCE, self::SORT_NAME, self::SORT_PRICE_ASC, self::SORT_PRICE_DESC])]
        public readonly ?string $sort = null,
    ) {
    }

    // Set after the constructor, not through it: a value the query-string denormalizer rejects as an int ("1.5") is
    // reported as a type error and leaves the default here, while a constructor parameter would get the raw string,
    // which PHP cuts to 1 (and deprecates doing so). Declared after the constructor to keep the order of the schema.
    #[Assert\Positive]
    public int $page = 1;
    #[Assert\Range(min: 1, max: 48)]
    public int $perPage = 24;

    /**
     * The phrase trimmed by the rule of texts (white space and invisible characters at its ends), null without `q`.
     */
    public function phrase(): ?string
    {
        return null === $this->q ? null : Text::normalize($this->q);
    }

    /**
     * A phrase with a forbidden character of the rule of texts is refused; a phrase that is not valid UTF-8 is left to
     * the length check, which names the charset.
     */
    public static function validatePhraseCharacters(mixed $phrase, ExecutionContextInterface $context): void
    {
        if (\is_string($phrase) && mb_check_encoding($phrase, 'UTF-8') && Text::hasForbiddenCharacters($phrase)) {
            $context->buildViolation(self::BAD_PHRASE)->addViolation();
        }
    }

    public function priceMin(): ?int
    {
        return null === $this->priceMin ? null : (int) $this->priceMin;
    }

    public function priceMax(): ?int
    {
        return null === $this->priceMax ? null : (int) $this->priceMax;
    }

    #[Assert\Callback]
    public function validateCombinations(ExecutionContextInterface $context): void
    {
        if (1 === preg_match(self::GROSZE_PATTERN, $this->priceMin ?? '') && 1 === preg_match(self::GROSZE_PATTERN, $this->priceMax ?? '') && $this->priceMin() > $this->priceMax()) {
            $context->buildViolation('Cena minimalna nie może być wyższa niż maksymalna.')->atPath('priceMin')->addViolation();
        }
        if (self::SORT_RELEVANCE === $this->sort && null === $this->q) {
            $context->buildViolation('To sortowanie wymaga szukanej frazy.')->atPath('sort')->addViolation();
        }
    }
}
