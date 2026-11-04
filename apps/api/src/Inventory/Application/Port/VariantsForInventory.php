<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\VariantId;

/**
 * All variants of Catalog in batches by id, for the demo stock.
 */
interface VariantsForInventory
{
    /**
     * @param int $limit 1 to 1000
     *
     * @return list<CatalogVariant> ordered by id, starting after $after; empty after the last variant
     */
    public function page(?VariantId $after, int $limit): array;
}
