<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Event\DomainEvent;

/**
 * Recorded by an edit, a publication and an unpublication of a product. Internal to Catalog: the command handler
 * publishes it as the integration event {@see \App\Catalog\Application\Event\ProductChanged}. `slug` is the slug
 * after the change.
 */
final readonly class ProductChanged implements DomainEvent
{
    public function __construct(public string $productId, public string $slug, public \DateTimeImmutable $occurredAt)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
