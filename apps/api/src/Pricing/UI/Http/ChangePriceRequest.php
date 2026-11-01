<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Domain\Price\GrossPrice;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of PUT /api/v1/admin/products/{productId}/price. Both keys are required. The values are taken as JSON gives
 * them and validated, never cast: a number in a string or with a fraction (also `100.0`) is a violation, not an
 * integer. Typed `int` properties would let PHP cast such a value on the way in. The actor is never part of the body,
 * it comes from the token.
 */
#[OA\Schema(description: 'A new gross price, based on the version of the price the staff member saw.', required: ['gross', 'version'])]
final readonly class ChangePriceRequest
{
    public function __construct(
        #[OA\Property(type: 'integer', description: 'Gross price (VAT included) in grosze, from 1 (0,01 zł) to 99 999 999 (999 999,99 zł).', minimum: GrossPrice::MIN, maximum: GrossPrice::MAX, example: 5990)]
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(min: GrossPrice::MIN, max: GrossPrice::MAX)]
        public mixed $gross,
        #[OA\Property(type: 'integer', description: 'The `version` of the price the change is based on; null when the product has no price yet.', nullable: true, example: 1)]
        #[Assert\Type('integer')]
        public mixed $version,
    ) {
    }
}
