<?php

declare(strict_types=1);

namespace App\Catalog\Application\Event;

use App\Catalog\Domain\Product\ProductChanged as ProductChangedInCatalog;
use App\Catalog\Domain\Product\ProductCreated as ProductCreatedInCatalog;
use App\Shared\Domain\Event\DomainEvent;

/**
 * The events of the product aggregate as Catalog publishes them: its ProductCreated and ProductChanged as the
 * integration contracts of this namespace, any other event as it is.
 */
final class IntegrationEvents
{
    /**
     * @param list<DomainEvent> $events
     *
     * @return list<DomainEvent>
     */
    public static function translate(array $events): array
    {
        return array_map(
            static fn (DomainEvent $event): DomainEvent => match (true) {
                $event instanceof ProductCreatedInCatalog => ProductCreated::from($event),
                $event instanceof ProductChangedInCatalog => ProductChanged::from($event),
                default => $event,
            },
            $events,
        );
    }
}
