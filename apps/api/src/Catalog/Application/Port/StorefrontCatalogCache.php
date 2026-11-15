<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * The cache of the store for the whole catalog (the tag `catalog`): Varnish, then the store.
 */
interface StorefrontCatalogCache
{
    /**
     * @throws \RuntimeException when Varnish or the store did not take the invalidation
     */
    public function invalidateCatalog(): void;
}
