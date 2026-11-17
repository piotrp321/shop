<?php

declare(strict_types=1);

namespace App\Catalog\Application\Event;

use App\Catalog\Domain\Product\ProductCreated as ProductCreatedInCatalog;
use App\Shared\Domain\Event\DomainEvent;

/**
 * Integration event, a public contract of Catalog like {@see ProductChanged}: a product was created (in the panel as
 * a draft, by the seed as published). Published by CreateProduct through the outbox; other modules subscribe to it on
 * the `events` transport and read what they need through the queries of Catalog. A change of its fields reaches every
 * subscriber.
 */
final readonly class ProductCreated implements DomainEvent
{
    public function __construct(public string $productId, public \DateTimeImmutable $occurredAt)
    {
    }

    public static function from(ProductCreatedInCatalog $event): self
    {
        return new self($event->productId, $event->occurredAt);
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
