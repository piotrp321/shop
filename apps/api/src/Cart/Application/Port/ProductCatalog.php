<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\ProductId;

/**
 * The products Catalog has. A cart keeps no reference to Catalog in its tables.
 */
interface ProductCatalog
{
    /**
     * One read for all the products; a product Catalog does not know is left out.
     *
     * @param list<ProductId> $ids 0 to 100 distinct ids
     *
     * @return list<ProductSummary>
     */
    public function summaries(array $ids): array;
}
