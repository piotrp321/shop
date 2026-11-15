<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\StorefrontCatalogCache;

final class SpyStorefrontCatalogCache implements StorefrontCatalogCache
{
    public int $invalidations = 0;
    public ?\RuntimeException $failure = null;

    public function invalidateCatalog(): void
    {
        ++$this->invalidations;
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
