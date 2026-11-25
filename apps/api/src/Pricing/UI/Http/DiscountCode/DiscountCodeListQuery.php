<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Pricing\Application\Query\ListDiscountCodes;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Shared\Domain\Text;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query string of GET /api/v1/admin/discount-codes. Every parameter is taken as the query gives it (a string, or an
 * array for `q[]=`), so a value of a wrong type is a violation with the Polish message of its parameter rather than
 * the framework's English text of a failed denormalization. The messages never contain the submitted value.
 */
final readonly class DiscountCodeListQuery
{
    /** The values of DiscountCodeStatus. */
    public const array STATUSES = ['disabled', 'planned', 'active', 'expired'];
    /** Longer than any cursor the API hands out (a moment and a UUID in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;
    /** Also the message of a cursor nobody issued (ListDiscountCodesController). */
    public const string CURSOR_MESSAGE = 'Kursor stronicowania jest nieprawidłowy.';
    private const string STATUS_MESSAGE = 'Status kodu musi być jednym z: disabled, planned, active, expired.';
    private const string PREFIX_MESSAGE = 'Początek kodu musi mieć od 1 do 32 znaków.';
    private const string PAGE_MESSAGE = 'Liczba kodów na stronie musi być od 1 do 100.';

    public function __construct(
        #[OA\Property(type: 'string', nullable: true)]
        #[Assert\Sequentially([
            new Assert\Type('string', message: self::STATUS_MESSAGE),
            new Assert\Choice(choices: self::STATUSES, message: self::STATUS_MESSAGE),
        ])]
        public mixed $status = null,
        #[OA\Property(type: 'string', nullable: true)]
        #[Assert\Sequentially([
            new Assert\Type('string', message: self::PREFIX_MESSAGE),
            // The rule of texts (Text): the characters of the phrase as it came, then the length of the trimmed phrase.
            new Assert\Callback([self::class, 'validatePrefixCharacters']),
            new Assert\Length(min: 1, max: DiscountCodeValue::MAX_LENGTH, minMessage: self::PREFIX_MESSAGE, maxMessage: self::PREFIX_MESSAGE, charsetMessage: self::PREFIX_MESSAGE, normalizer: [Text::class, 'normalize']),
        ])]
        public mixed $q = null,
        #[OA\Property(type: 'string', nullable: true)]
        #[Assert\Sequentially([
            new Assert\Type('string', message: self::CURSOR_MESSAGE),
            new Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH, minMessage: self::CURSOR_MESSAGE, maxMessage: self::CURSOR_MESSAGE, charsetMessage: self::CURSOR_MESSAGE),
        ])]
        public mixed $after = null,
        // Digits only, so neither "2.5" nor "1e2" is a page size; an empty one is no number for the range.
        #[OA\Property(type: 'integer', default: ListDiscountCodes::DEFAULT_PER_PAGE)]
        #[Assert\Sequentially([
            new Assert\Type('string', message: self::PAGE_MESSAGE),
            new Assert\Regex(pattern: '/^[0-9]{1,6}$/D', message: self::PAGE_MESSAGE),
            new Assert\Range(min: 1, max: ListDiscountCodes::MAX_PER_PAGE, notInRangeMessage: self::PAGE_MESSAGE, invalidMessage: self::PAGE_MESSAGE),
        ])]
        public mixed $perPage = null,
    ) {
    }

    // The accessors are not named after the parameters: the serializer would take the type of a method named so.

    public function statusFilter(): ?string
    {
        return \is_string($this->status) ? $this->status : null;
    }

    /**
     * The beginning of a code the list is filtered by: `q` trimmed by the rule of texts, and by nothing else: a format
     * character T2 leaves next to a visible one stays, so the phrase is the beginning of no code. What a code is made
     * of is the alphabet of DiscountCodeValue, which the list applies to it.
     */
    public function codePrefix(): ?string
    {
        return \is_string($this->q) ? Text::normalize($this->q) : null;
    }

    /**
     * A phrase with a forbidden character of the rule of texts is refused; one that is not valid UTF-8 is left to the
     * length check, which names the charset.
     */
    public static function validatePrefixCharacters(mixed $prefix, ExecutionContextInterface $context): void
    {
        if (\is_string($prefix) && mb_check_encoding($prefix, 'UTF-8') && Text::hasForbiddenCharacters($prefix)) {
            $context->buildViolation(self::PREFIX_MESSAGE)->addViolation();
        }
    }

    public function cursor(): ?string
    {
        return \is_string($this->after) ? $this->after : null;
    }

    public function pageSize(): int
    {
        return \is_string($this->perPage) ? (int) $this->perPage : ListDiscountCodes::DEFAULT_PER_PAGE;
    }
}
