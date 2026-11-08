<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body of POST /api/v1/admin/discount-codes. Every key is required (the nullable ones may be null). DiscountCodeBodyReader gives a missing one as MissingKey, a violation at its field. The values are
 * taken as JSON gives them and validated, never cast: a number in a string or with a fraction (also `10.0`) is a
 * violation. The actor is never part of the body, it comes from the token.
 */
#[OA\Schema(description: 'A new discount code.', required: ['code', 'kind', 'value', 'minimumOrderGross', 'startsAt', 'endsAt', 'usageLimit', 'enabled'])]
final readonly class CreateDiscountCodeRequest
{
    public function __construct(
        #[OA\Property(type: 'string', description: 'Once trimmed, 3 to 32 characters: letters A–Z, digits and the dash, in any case (stored in upper case). Unique among all codes for ever, without regard to case; it never changes.', maxLength: DiscountCodeValue::MAX_LENGTH, minLength: DiscountCodeValue::MIN_LENGTH, example: 'WITAJ10')]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\NotNull(message: 'Kod ma 3–32 znaki: litery, cyfry i myślnik.'),
            new Assert\Type('string', message: 'Kod ma 3–32 znaki: litery, cyfry i myślnik.'),
        ])]
        public mixed $code,
        #[OA\Property(type: 'string', description: 'A percent of the order or an amount.', enum: DiscountCodeBodyRules::KINDS, example: 'percent')]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\NotNull(message: DiscountCodeBodyRules::KIND),
            new Assert\Type('string', message: DiscountCodeBodyRules::KIND),
            new Assert\Choice(choices: DiscountCodeBodyRules::KINDS, message: DiscountCodeBodyRules::KIND),
        ])]
        public mixed $kind,
        #[OA\Property(type: 'integer', description: 'The percent (1 to 90) for `percent`, the amount in grosze (100 to 1 000 000, 1 to 10 000 zł) for `amount`.', example: 10)]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\NotNull(message: DiscountCodeBodyRules::VALUE),
            new Assert\Type('integer', message: DiscountCodeBodyRules::VALUE),
        ])]
        public mixed $value,
        #[OA\Property(type: 'integer', description: 'The smallest order (gross of the effective prices, before the code, in grosze) the code works from; required and greater than the amount for `amount`.', maximum: MinimumOrder::MAX, minimum: MinimumOrder::MIN, nullable: true, example: 30000)]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\Type('integer', message: DiscountCodeBodyRules::MINIMUM),
        ])]
        public mixed $minimumOrderGross,
        #[OA\Property(type: 'string', format: 'date-time', description: 'RFC 3339 with a time zone; a moment in the past makes the code active at once. A fraction of a second is cut.', example: '2026-11-11T00:00:00+01:00')]
        public mixed $startsAt,
        #[OA\Property(type: 'string', format: 'date-time', description: 'RFC 3339 with a time zone, after the start; the code is valid until before this moment. Null: no end. A fraction of a second is cut.', nullable: true, example: '2026-12-01T00:00:00+01:00')]
        public mixed $endsAt,
        #[OA\Property(type: 'integer', description: 'How many orders in total may use the code (orders cancelled unpaid give their use back). Null: no limit.', maximum: UsageLimit::MAX, minimum: UsageLimit::MIN, nullable: true, example: 100)]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\Type('integer', message: DiscountCodeBodyRules::USAGE_LIMIT),
        ])]
        public mixed $usageLimit,
        #[OA\Property(type: 'boolean', description: 'A disabled code gives no discount whatever its period.', example: true)]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\NotNull(message: DiscountCodeBodyRules::ENABLED),
            new Assert\Type('bool', message: DiscountCodeBodyRules::ENABLED),
        ])]
        public mixed $enabled,
    ) {
    }

    #[Assert\Callback]
    public function validateMoments(ExecutionContextInterface $context): void
    {
        DiscountCodeBodyRules::validate($context, $this->startsAt, $this->endsAt);
    }
}
