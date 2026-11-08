<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

/**
 * Changes a promotion, based on the version the staff member saw. The whole promotion is sent; what may change is
 * decided by Promotion::revise (Q5).
 */
final readonly class RevisePromotion
{
    /**
     * @param list<string> $variantIds variant UUIDs
     * @param string       $actorId    the `sub` of the backoffice token
     * @param string       $actorName  the name of the staff member from the token
     */
    public function __construct(
        public string $id,
        public int $expectedVersion,
        public string $name,
        public int $percentOff,
        public ?\DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public array $variantIds,
        public string $actorId,
        public string $actorName,
    ) {
    }
}
