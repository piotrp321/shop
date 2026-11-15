<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Storefront;

use App\Catalog\Application\Event\CategoryTreeChanged;
use App\Shared\Infrastructure\Http\Cache\CacheTags;
use App\Shared\Infrastructure\Http\Cache\StorefrontCache;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A change of the category tree makes the menu, the breadcrumbs, the category pages, the listings and the redirects of
 * the whole catalog stale (spec ADMIN-FEATURES, 4 "Odświeżenie sklepu i reindeksacja"): purges the tag `catalog` in
 * Varnish, then revalidates it in the store, once per event. Changes of the tree are rare, so the whole catalog is
 * invalidated instead of tags per category. Reads nothing; idempotent like every invalidation.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class InvalidateStorefrontOnCategoryTreeChange
{
    public function __construct(private StorefrontCache $cache)
    {
    }

    /**
     * @throws StorefrontCacheFailure Varnish or the store failed; Messenger retries the message
     */
    public function __invoke(CategoryTreeChanged $event): void
    {
        $this->cache->invalidateTags([CacheTags::CATALOG]);
    }
}
