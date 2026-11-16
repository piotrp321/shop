<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Storefront;

use App\Catalog\Application\Port\StorefrontCatalogCache;
use App\Shared\Infrastructure\Http\Cache\CacheTags;
use App\Shared\Infrastructure\Http\Cache\StorefrontCache;

/**
 * Purges the tag `catalog` in Varnish, then revalidates it in the store; a failure is a StorefrontCacheFailure.
 */
final readonly class HttpStorefrontCatalogCache implements StorefrontCatalogCache
{
    public function __construct(private StorefrontCache $cache)
    {
    }

    public function invalidateCatalog(): void
    {
        $this->cache->invalidateTags([CacheTags::CATALOG]);
    }
}
