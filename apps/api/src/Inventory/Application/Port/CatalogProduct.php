<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\ProductId;

/**
 * A product of Catalog as the demo stock needs it: its id and slug.
 */
final readonly class CatalogProduct
{
    public function __construct(
        public ProductId $id,
        public string $slug,
    ) {
    }
}
