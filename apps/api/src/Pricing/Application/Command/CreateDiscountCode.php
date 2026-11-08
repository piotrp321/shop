<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Domain\DiscountCode\DiscountKind;

/**
 * Creates a discount code. A staff change names the staff member; the demo data have no actor.
 */
final readonly class CreateDiscountCode
{
    /**
     * @param string      $id        the UUID of the new code, chosen by the caller
     * @param string      $code      the text as typed; the case does not matter
     * @param int         $value     the percent, or the amount in grosze
     * @param string|null $actorId   the `sub` of the backoffice token, null for the system
     * @param string|null $actorName the name of the staff member from the token, null for the system
     */
    public function __construct(
        public string $id,
        public string $code,
        public DiscountKind $kind,
        public int $value,
        public ?int $minimumOrderGross,
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
        public ?int $usageLimit,
        public bool $enabled,
        public ?string $actorId,
        public ?string $actorName,
    ) {
    }
}
