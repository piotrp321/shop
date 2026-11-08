<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Storefront;

use App\Pricing\Domain\Promotion\PromotionPriceChangeSource;
use App\Pricing\Domain\Promotion\PromotionPricesChanged;
use App\Shared\Infrastructure\Http\Cache\StorefrontCache;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A promotion that starts, ends or moves its end makes the cached pages of its product stale: purges the product in
 * Varnish, then revalidates it in the store. The event carries the product, so Catalog is not asked for it.
 * Idempotent: a purge and a revalidation of a tag only refresh it from the current data, however often they run.
 *
 * A change from the demo seed is acked without any request: `make seed` ends with storefront:cache:purge-all.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class InvalidateStorefrontOnPromotionChange
{
    public function __construct(private StorefrontCache $cache)
    {
    }

    /**
     * @throws StorefrontCacheFailure Varnish or the store failed; Messenger retries the message
     */
    public function __invoke(PromotionPricesChanged $event): void
    {
        if (PromotionPriceChangeSource::Seed === $event->source) {
            return;
        }

        $this->cache->invalidateProduct($event->productId);
    }
}
