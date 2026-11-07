<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Storefront;

use App\Catalog\Application\Event\ProductChanged;
use App\Shared\Infrastructure\Http\Cache\CacheTags;
use App\Shared\Infrastructure\Http\Cache\StorefrontCache;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * An edited, published or unpublished product makes its cached responses and pages stale (decisions A2, A4): purges
 * the tag of the product and the tag of its slug in Varnish, then revalidates both in the store. The tag of the slug
 * drops the 404 of a slug visited before the publication. Reads nothing: the event names the product and its slug.
 * Idempotent: a purge and a revalidation only refresh the tags from the current data, however often they run.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class InvalidateStorefrontOnProductChange
{
    public function __construct(private StorefrontCache $cache)
    {
    }

    /**
     * @throws StorefrontCacheFailure                Varnish or the store failed; Messenger retries the message
     * @throws UnrecoverableMessageHandlingException the id or the slug of the event is malformed; the message goes to `failed`
     */
    public function __invoke(ProductChanged $event): void
    {
        try {
            $tags = [CacheTags::product($event->productId), CacheTags::productSlug($event->slug)];
        } catch (\InvalidArgumentException $e) {
            // Catalog publishes only what it accepted, so a retry cannot fix this.
            throw new UnrecoverableMessageHandlingException('ProductChanged does not name a product and a slug.', previous: $e);
        }

        $this->cache->invalidateTags($tags);
    }
}
