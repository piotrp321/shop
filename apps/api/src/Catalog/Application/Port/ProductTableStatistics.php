<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * What the database knows about the size of the table of products without reading it: its own estimate, which the
 * dashboard shows as the number of products (a count of millions of rows is never made for a page), and the refresh of
 * that estimate after a mass change.
 */
interface ProductTableStatistics
{
    /**
     * The number of products the planner statistics hold (`pg_class.reltuples`), exact only right after a refresh;
     * null while the table has no statistics yet (never analysed). Reads one row of the catalogue of the database,
     * never the products.
     */
    public function estimatedProductCount(): ?int;

    /**
     * Refreshes the statistics of the table (ANALYZE), which a mass change of products (seed, import, backfill) has to
     * do, because the autovacuum may come late.
     */
    public function refresh(): void;
}
