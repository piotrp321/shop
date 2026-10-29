<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Search\ProductSearchSnapshot;

interface ProductSearchSource
{
    /**
     * Reads at most $limit products with an id greater than $afterId, ordered by id, from one consistent snapshot.
     * Memory use depends on $limit, not on the size of the catalog.
     *
     * @param string|null $afterId the last id of the previous page (a UUID, case-insensitive), null to start from the beginning
     *
     * @return list<ProductSearchSnapshot> an empty list means there are no more products
     *
     * @throws \InvalidArgumentException when $limit is lower than 1 or $afterId is not a UUID
     */
    public function page(?string $afterId, int $limit): array;
}
