<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Domain\DiscountCode\DiscountKind;

/**
 * Changes a discount code, based on the version the staff member saw. The whole code but its text is sent; what may
 * change is decided by DiscountCode::revise (Q3).
 */
final readonly class ReviseDiscountCode
{
    /**
     * @param int    $value     the percent, or the amount in grosze
     * @param string $actorId   the `sub` of the backoffice token
     * @param string $actorName the name of the staff member from the token
     */
    public function __construct(
        public string $id,
        public int $expectedVersion,
        public DiscountKind $kind,
        public int $value,
        public ?int $minimumOrderGross,
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
        public ?int $usageLimit,
        public bool $enabled,
        public string $actorId,
        public string $actorName,
    ) {
    }
}
