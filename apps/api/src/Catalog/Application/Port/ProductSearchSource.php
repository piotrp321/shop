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

    /**
     * Reads one product with the same query as page().
     *
     * @param string $id a UUID, case-insensitive
     *
     * @return ProductSearchSnapshot|null null when there is no such product
     *
     * @throws \InvalidArgumentException when $id is not a UUID
     */
    public function find(string $id): ?ProductSearchSnapshot;

    /**
     * Reads the given products with the same query as page(), in one statement, ordered by id. An id without a product
     * is passed over and a repeated id gives one snapshot.
     *
     * @param list<string> $ids UUIDs, case-insensitive
     *
     * @return list<ProductSearchSnapshot>
     *
     * @throws \InvalidArgumentException when an id is not a UUID
     */
    public function findMany(array $ids): array;

    /**
     * Reads at most $limit product ids greater than $afterId, ordered by id: the keys alone, without the joins of
     * page(), for the producer of an asynchronous rebuild.
     *
     * @param string|null $afterId a UUID, case-insensitive; null to start from the beginning
     *
     * @return list<string> lower-case; an empty list means there are no more products
     *
     * @throws \InvalidArgumentException when $limit is lower than 1 or $afterId is not a UUID
     */
    public function idsAfter(?string $afterId, int $limit): array;
}
