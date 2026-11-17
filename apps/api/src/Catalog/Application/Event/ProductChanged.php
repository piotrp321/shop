<?php

declare(strict_types=1);

namespace App\Catalog\Application\Event;

use App\Catalog\Domain\Product\ProductChanged as ProductChangedInCatalog;
use App\Shared\Domain\Event\DomainEvent;

/**
 * Integration event, a public contract of Catalog like its queries (decision A2): a product was edited, published or
 * unpublished. Published by the command handlers through the outbox (translated by {@see IntegrationEvents}); other
 * modules subscribe to it on the `events` transport and read what they need through the queries of Catalog. `slug` is
 * the slug after the change. A change of its fields reaches every subscriber.
 */
final readonly class ProductChanged implements DomainEvent
{
    public function __construct(public string $productId, public string $slug, public \DateTimeImmutable $occurredAt)
    {
    }

    public static function from(ProductChangedInCatalog $event): self
    {
        return new self($event->productId, $event->slug, $event->occurredAt);
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
