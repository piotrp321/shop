<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

/**
 * Plans a new promotion. A start that is null or not after now starts it now (at the start of now).
 */
final readonly class PlanPromotion
{
    /**
     * @param string       $id         the UUID of the new promotion, chosen by the caller
     * @param list<string> $variantIds variant UUIDs
     */
    private function __construct(
        public string $id,
        public string $name,
        public int $percentOff,
        public ?\DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public array $variantIds,
        public ?string $actorId,
        public ?string $actorName,
    ) {
    }

    /**
     * @param list<string> $variantIds
     * @param string       $actorId    the `sub` of the backoffice token
     * @param string       $actorName  the name of the staff member from the token
     */
    public static function byStaff(string $id, string $name, int $percentOff, ?\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, array $variantIds, string $actorId, string $actorName): self
    {
        return new self($id, $name, $percentOff, $startsAt, $endsAt, $variantIds, $actorId, $actorName);
    }
}
