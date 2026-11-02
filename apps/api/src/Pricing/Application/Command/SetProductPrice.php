<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Domain\Price\PriceChangeSource;

/**
 * Sets the gross price of a product. A staff change names the staff member, a seed change has no actor.
 */
final readonly class SetProductPrice
{
    /**
     * @param int      $gross           in grosze
     * @param int|null $expectedVersion the version the change is based on; null when the product had no price
     */
    private function __construct(
        public string $productId,
        public int $gross,
        public ?int $expectedVersion,
        public PriceChangeSource $source,
        public ?string $actorId,
        public ?string $actorName,
    ) {
    }

    /**
     * @param string $actorId   the `sub` of the backoffice token
     * @param string $actorName the name of the staff member from the token
     */
    public static function byStaff(string $productId, int $gross, ?int $expectedVersion, string $actorId, string $actorName): self
    {
        return new self($productId, $gross, $expectedVersion, PriceChangeSource::Staff, $actorId, $actorName);
    }

    /**
     * The demo data: a product that has no price yet.
     */
    public static function bySeed(string $productId, int $gross): self
    {
        return new self($productId, $gross, null, PriceChangeSource::Seed, null, null);
    }
}
