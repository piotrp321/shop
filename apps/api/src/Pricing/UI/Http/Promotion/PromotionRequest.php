<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body of POST /api/v1/admin/promotions. Every key is required (`startsAt` may be null). The values are taken as JSON
 * gives them and validated, never cast: a number in a string or with a fraction (also `20.0`) is a violation. The actor
 * is never part of the body, it comes from the token.
 */
#[OA\Schema(description: 'A new promotion.', required: ['name', 'percentOff', 'startsAt', 'endsAt', 'variantIds'])]
final readonly class PromotionRequest
{
    public function __construct(
        #[OA\Property(type: 'string', description: '1 to 100 characters once trimmed, without control characters.', maxLength: Promotion::MAX_NAME_LENGTH, example: 'Jesień w górach')]
        #[Assert\NotNull]
        #[Assert\Type('string')]
        public mixed $name,
        #[OA\Property(type: 'integer', description: 'The discount of every variant, in percent.', minimum: PercentOff::MIN, maximum: PercentOff::MAX, example: 20)]
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        public mixed $percentOff,
        #[OA\Property(type: 'string', format: 'date-time', description: 'RFC 3339 with a time zone. Null, now or a moment before now: the promotion starts now (at the next whole second). A fraction of a second is cut.', nullable: true, example: '2026-11-11T00:00:00+01:00')]
        public mixed $startsAt,
        #[OA\Property(type: 'string', format: 'date-time', description: 'RFC 3339 with a time zone, after now and the start, at most 90 days after the start. The promotion is in force until before this moment. A fraction of a second is cut.', example: '2026-11-21T00:00:00+01:00')]
        #[Assert\NotNull]
        public mixed $endsAt,
        #[OA\Property(type: 'array', description: '1 to 1000 variants, each once; every one must exist in the catalog and have a current price.', items: new OA\Items(type: 'string', format: 'uuid'), maxItems: Promotion::MAX_VARIANTS, minItems: Promotion::MIN_VARIANTS)]
        #[Assert\NotNull]
        public mixed $variantIds,
    ) {
    }

    #[Assert\Callback]
    public function validateShape(ExecutionContextInterface $context): void
    {
        PromotionBodyRules::validate($context, $this->startsAt, $this->endsAt, $this->variantIds);
    }
}
