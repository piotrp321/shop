<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

/**
 * Who changed a price: a staff member (the `sub` and the name from the backoffice token) or the system (no actor).
 */
final readonly class PriceChangeOrigin
{
    private const int MAX_ACTOR_LENGTH = 255;

    private function __construct(
        private PriceChangeSource $source,
        private ?string $actorId,
        private ?string $actorName,
    ) {
    }

    public static function staff(string $actorId, string $actorName): self
    {
        if ('' === $actorId || \strlen($actorId) > self::MAX_ACTOR_LENGTH) {
            throw new \InvalidArgumentException('A staff change needs the actor id, at most 255 bytes.');
        }
        if ('' === trim($actorName) || mb_strlen($actorName) > self::MAX_ACTOR_LENGTH) {
            throw new \InvalidArgumentException('A staff change needs the actor name, at most 255 characters.');
        }

        return new self(PriceChangeSource::Staff, $actorId, $actorName);
    }

    public static function seed(): self
    {
        return new self(PriceChangeSource::Seed, null, null);
    }

    public static function taxReconciliation(): self
    {
        return new self(PriceChangeSource::TaxReconciliation, null, null);
    }

    public function source(): PriceChangeSource
    {
        return $this->source;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function actorName(): ?string
    {
        return $this->actorName;
    }
}
