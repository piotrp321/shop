<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

/**
 * Who changed a stock: a staff member (the `sub` and the name from the backoffice token), the demo data or the
 * system (the reservation port). Only a staff change has an actor.
 */
final readonly class StockChangeOrigin
{
    private const int MAX_ACTOR_LENGTH = 255;

    private function __construct(
        private StockChangeSource $source,
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

        return new self(StockChangeSource::Staff, $actorId, $actorName);
    }

    public static function seed(): self
    {
        return new self(StockChangeSource::Seed, null, null);
    }

    public static function system(): self
    {
        return new self(StockChangeSource::System, null, null);
    }

    public function source(): StockChangeSource
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
