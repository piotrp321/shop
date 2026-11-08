<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Pricing\Application\Query\ListDiscountCodes;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of GET /api/v1/admin/discount-codes. The violation messages never contain the submitted value.
 */
final readonly class DiscountCodeListQuery
{
    /** The values of DiscountCodeStatus. */
    public const array STATUSES = ['disabled', 'planned', 'active', 'expired'];
    /** Longer than any cursor the API hands out (a moment and a UUID in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;
    /** Also the message of a cursor nobody issued (ListDiscountCodesController). */
    public const string CURSOR_MESSAGE = 'Kursor stronicowania jest nieprawidłowy.';
    private const string PREFIX_MESSAGE = 'Początek kodu musi mieć od 1 do 32 znaków.';

    public function __construct(
        #[Assert\Choice(choices: self::STATUSES, message: 'Status kodu musi być jednym z: disabled, planned, active, expired.')]
        public ?string $status = null,
        #[Assert\Length(min: 1, max: DiscountCodeValue::MAX_LENGTH, minMessage: self::PREFIX_MESSAGE, maxMessage: self::PREFIX_MESSAGE, charsetMessage: self::PREFIX_MESSAGE, normalizer: 'trim')]
        public ?string $q = null,
        #[Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH, minMessage: self::CURSOR_MESSAGE, maxMessage: self::CURSOR_MESSAGE, charsetMessage: self::CURSOR_MESSAGE)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: ListDiscountCodes::MAX_PER_PAGE, notInRangeMessage: 'Liczba kodów na stronie musi być od {{ min }} do {{ max }}.')]
        public int $perPage = ListDiscountCodes::DEFAULT_PER_PAGE,
    ) {
    }
}
