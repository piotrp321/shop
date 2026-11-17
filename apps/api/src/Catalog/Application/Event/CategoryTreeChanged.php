<?php

declare(strict_types=1);

namespace App\Catalog\Application\Event;

use App\Shared\Domain\Event\DomainEvent;

/**
 * Integration event, a public contract of Catalog like {@see ProductChanged}: the category tree changed (spec
 * ADMIN-FEATURES, 4 "Odświeżenie sklepu i reindeksacja"). Published by the commands of the tree through the outbox,
 * not by the seed; other modules read what they need through the queries of Catalog. A change of its fields reaches
 * every subscriber.
 */
final readonly class CategoryTreeChanged implements DomainEvent
{
    public function __construct(
        public string $categoryId,
        public CategoryTreeChangeKind $kind,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
