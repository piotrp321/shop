<?php

declare(strict_types=1);

namespace App\Catalog\Application\Event;

use App\Catalog\Domain\Product\ProductChanged as ProductChangedInCatalog;
use App\Catalog\Domain\Product\ProductCreated as ProductCreatedInCatalog;
use App\Shared\Domain\Event\DomainEvent;

/**
 * The events of the product aggregate as Catalog publishes them: its ProductCreated and ProductChanged as the
 * integration contracts of this namespace, any other event as it is.
 *
 * Version compatibility: the aggregate's ProductCreated is published as it is too, before its contract. The previous
 * version indexes a created product from that event only, and its outbox may still hold it for the new IndexProduct.
 * Remove it, and its type in IndexProduct, in the next version.
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
        $published = [];
        foreach ($events as $event) {
            if ($event instanceof ProductCreatedInCatalog) {
                $published[] = $event;
            }
            $published[] = match (true) {
                $event instanceof ProductCreatedInCatalog => ProductCreated::from($event),
                $event instanceof ProductChangedInCatalog => ProductChanged::from($event),
                default => $event,
            };
        }

        return $published;
    }
}
