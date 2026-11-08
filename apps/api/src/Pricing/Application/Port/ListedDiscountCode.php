<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\DiscountCode\DiscountKind;

/**
 * A discount code as the list of the panel shows it, with the number of its active usages.
 */
final readonly class ListedDiscountCode
{
    /**
     * @param int $value the percent, or the amount in grosze
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
        public int $activeUses,
        public int $version,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
