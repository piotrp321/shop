<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * Removes all catalog data: the catalog tables, the pending outbox events, the stored originals and the search
 * index. Only for local seeding and tests.
 */
interface CatalogReset
{
    public function reset(): void;
}
