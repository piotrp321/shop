<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\VariantId;

/**
 * The product variants Catalog has. Inventory stocks only those, but keeps no reference to Catalog in its tables.
 */
interface VariantCatalog
{
    public function exists(VariantId $id): bool;
}
