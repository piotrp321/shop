<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

/**
 * A promotion as the list of the panel shows it.
 */
final readonly class ListedPromotion
{
    public function __construct(
        public string $id,
        public string $name,
        public int $percentOff,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public int $variantCount,
        public int $version,
    ) {
    }
}
