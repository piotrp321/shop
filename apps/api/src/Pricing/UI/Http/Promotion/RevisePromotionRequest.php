<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body of PUT /api/v1/admin/promotions/{id}: the whole promotion as the staff member wants it, and the version it is
 * based on. Strict types like PromotionRequest; every key is required.
 */
#[OA\Schema(description: 'A promotion as it should be. Once started only the name and the end may differ from the stored values.', required: ['name', 'percentOff', 'startsAt', 'endsAt', 'variantIds', 'version'])]
final readonly class RevisePromotionRequest
{
    public function __construct(
        #[OA\Property(type: 'string', description: '1 to 100 characters once trimmed, without control characters.', maxLength: Promotion::MAX_NAME_LENGTH, example: 'Jesień w górach')]
        #[Assert\NotNull]
        #[Assert\Type('string')]
        public mixed $name,
        #[OA\Property(type: 'integer', description: 'The discount of every variant, in percent. Fixed once the promotion started.', minimum: PercentOff::MIN, maximum: PercentOff::MAX, example: 20)]
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        public mixed $percentOff,
        #[OA\Property(type: 'string', format: 'date-time', description: 'RFC 3339 with a time zone; null, now or before now starts a planned promotion now. Fixed once the promotion started (send the stored one).', nullable: true, example: '2026-11-11T00:00:00+01:00')]
        public mixed $startsAt,
        #[OA\Property(type: 'string', format: 'date-time', description: 'RFC 3339 with a time zone, at most 90 days after the start. For an active promotion, now or before now ends it at once ("end now": the next whole second).', example: '2026-11-21T00:00:00+01:00')]
        #[Assert\NotNull]
        public mixed $endsAt,
        #[OA\Property(type: 'array', description: '1 to 1000 variants, each once. Fixed once the promotion started; an added one must exist in the catalog and have a current price.', items: new OA\Items(type: 'string', format: 'uuid'), maxItems: Promotion::MAX_VARIANTS, minItems: Promotion::MIN_VARIANTS)]
        #[Assert\NotNull]
        public mixed $variantIds,
        #[OA\Property(type: 'integer', description: 'The `version` of the promotion the change is based on.', example: 1)]
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        public mixed $version,
    ) {
    }

    #[Assert\Callback]
    public function validateShape(ExecutionContextInterface $context): void
    {
        PromotionBodyRules::validate($context, $this->startsAt, $this->endsAt, $this->variantIds);
    }
}
