<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Domain\DiscountCode\DiscountCodeActor;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\InvalidDiscountCode;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;

/**
 * The values of a code a command gives, shared by the create and the revise: built in the order of the fields of a
 * request, so the first invalid one is the one reported.
 */
final readonly class DiscountCodeTerms
{
    private function __construct(
        public DiscountValue $value,
        public ?MinimumOrder $minimumOrder,
        public ValidityPeriod $period,
        public ?UsageLimit $usageLimit,
    ) {
    }

    /**
     * @throws InvalidDiscountCode
     */
    public static function of(DiscountKind $kind, int $value, ?int $minimumOrderGross, \DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt, ?int $usageLimit): self
    {
        return new self(
            DiscountValue::of($kind, $value),
            null === $minimumOrderGross ? null : MinimumOrder::of($minimumOrderGross),
            ValidityPeriod::of($startsAt, $endsAt),
            null === $usageLimit ? null : UsageLimit::of($usageLimit),
        );
    }

    public static function actor(?string $id, ?string $name): ?DiscountCodeActor
    {
        return null === $id || null === $name ? null : DiscountCodeActor::staff($id, $name);
    }
}
