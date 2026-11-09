<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body of PUT /api/v1/admin/discount-codes/{id}: the whole code as the staff member wants it, without its text (it
 * never changes), and the version it is based on. Strict types like CreateDiscountCodeRequest; every key is required (DiscountCodeBodyReader).
 */
#[OA\Schema(description: 'A discount code as it should be. Once started only the end, `enabled` and an existing limit may differ from the stored values.', required: ['kind', 'value', 'minimumOrderGross', 'startsAt', 'endsAt', 'usageLimit', 'enabled', 'version'])]
final readonly class ReviseDiscountCodeRequest
{
    public function __construct(
        #[OA\Property(type: 'string', description: 'A percent of the order or an amount. Fixed once the code started.', enum: DiscountCodeBodyRules::KINDS, example: 'percent')]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\NotNull(message: DiscountCodeBodyRules::KIND),
            new Assert\Type('string', message: DiscountCodeBodyRules::KIND),
            new Assert\Choice(choices: DiscountCodeBodyRules::KINDS, message: DiscountCodeBodyRules::KIND),
        ])]
        public mixed $kind,
        #[OA\Property(type: 'integer', description: 'The percent (1 to 90) for `percent`, the amount in grosze (100 to 1 000 000) for `amount`. Fixed once the code started.', example: 10)]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\NotNull(message: DiscountCodeBodyRules::VALUE),
            new Assert\Type('integer', message: DiscountCodeBodyRules::VALUE),
        ])]
        public mixed $value,
        #[OA\Property(type: 'integer', description: 'In grosze; required and greater than the amount for `amount`. Fixed once the code started.', maximum: MinimumOrder::MAX, minimum: MinimumOrder::MIN, nullable: true, example: 30000)]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\Type('integer', message: DiscountCodeBodyRules::MINIMUM),
        ])]
        public mixed $minimumOrderGross,
        #[OA\Property(type: 'string', format: 'date-time', description: 'RFC 3339 with a time zone. Fixed once the code started (send the stored one).', example: '2026-11-11T00:00:00+01:00')]
        public mixed $startsAt,
        #[OA\Property(type: 'string', format: 'date-time', description: 'RFC 3339 with a time zone, after the start; a changed end must be after the time of the server (stopping a code at once is disabling it). Null: no end.', nullable: true, example: '2026-12-01T00:00:00+01:00')]
        public mixed $endsAt,
        #[OA\Property(type: 'integer', description: 'Not below the active usages. Once the code started a limit may change or be removed (null), but not be added.', maximum: UsageLimit::MAX, minimum: UsageLimit::MIN, nullable: true, example: 100)]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\Type('integer', message: DiscountCodeBodyRules::USAGE_LIMIT),
        ])]
        public mixed $usageLimit,
        #[OA\Property(type: 'boolean', example: true)]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\NotNull(message: DiscountCodeBodyRules::ENABLED),
            new Assert\Type('bool', message: DiscountCodeBodyRules::ENABLED),
        ])]
        public mixed $enabled,
        #[OA\Property(type: 'integer', description: 'The `version` of the code the change is based on.', minimum: 1, example: 1)]
        #[Assert\Sequentially([
            new Assert\Callback(DiscountCodeBodyRules::KEY_GIVEN),
            new Assert\NotNull(message: DiscountCodeBodyRules::VERSION),
            new Assert\Type('integer', message: DiscountCodeBodyRules::VERSION),
            new Assert\Positive(message: DiscountCodeBodyRules::VERSION),
        ])]
        public mixed $version,
    ) {
    }

    #[Assert\Callback]
    public function validateMoments(ExecutionContextInterface $context): void
    {
        DiscountCodeBodyRules::validate($context, $this->startsAt, $this->endsAt);
    }
}
