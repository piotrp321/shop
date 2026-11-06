<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Storefront;

use App\Catalog\Application\Query\GetVariant;
use App\Catalog\Application\Query\VariantInfo;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockLevelChanged;
use App\Shared\Infrastructure\Http\Cache\StorefrontCache;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A changed availability level makes the product's cached pages stale: purges the product in Varnish, then
 * revalidates it in the store. The product comes from Catalog (GetVariant on query.bus). Idempotent: a purge and a
 * revalidation of a tag only refresh it from the current data, however often they run.
 *
 * A change from the demo seed is acked without any request: `make seed` ends with storefront:cache:purge-all, and an
 * invalidation per seeded stock would hold up the queue of the worker.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final class InvalidateStorefrontOnStockLevelChange
{
    use HandleTrait;

    public function __construct(
        #[Autowire(service: 'query.bus')] MessageBusInterface $queryBus,
        private readonly StorefrontCache $cache,
    ) {
        $this->messageBus = $queryBus;
    }

    /**
     * @throws StorefrontCacheFailure Varnish or the store failed; Messenger retries the message
     */
    public function __invoke(StockLevelChanged $event): void
    {
        if (StockChangeSource::Seed === $event->source) {
            return;
        }
        $variant = $this->handle(new GetVariant($event->variantId));
        \assert(null === $variant || $variant instanceof VariantInfo);
        // A variant Catalog does not know has no page to refresh.
        if (null === $variant) {
            return;
        }

        $this->cache->invalidateProduct($variant->productId);
    }
}
