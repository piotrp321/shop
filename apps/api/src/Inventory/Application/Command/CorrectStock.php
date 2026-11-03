<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

/**
 * Sets the physical stock of a product to what a staff member counted, based on the version of the stock they saw.
 */
final readonly class CorrectStock
{
    /**
     * @param int      $countedPhysical 0 to 1 000 000 units
     * @param int|null $expectedVersion the version the correction is based on; null when the product had no stock
     * @param string   $reason          inventory_count, damage, loss or other (other needs a note)
     */
    private function __construct(
        public string $productId,
        public int $countedPhysical,
        public ?int $expectedVersion,
        public string $reason,
        public ?string $note,
        public string $actorId,
        public string $actorName,
    ) {
    }

    /**
     * @param string $actorId   the `sub` of the backoffice token
     * @param string $actorName the name of the staff member from the token
     */
    public static function byStaff(string $productId, int $countedPhysical, ?int $expectedVersion, string $reason, ?string $note, string $actorId, string $actorName): self
    {
        return new self($productId, $countedPhysical, $expectedVersion, $reason, $note, $actorId, $actorName);
    }
}
