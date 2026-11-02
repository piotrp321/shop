<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\ProductId;

/**
 * The products Catalog has. Inventory stocks only those, but keeps no reference to Catalog in its tables.
 */
interface ProductCatalog
{
    public function exists(ProductId $id): bool;
}
