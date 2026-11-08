<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Query\SitemapChunkKey;

/**
 * The products of the sitemap, read straight from the Catalog tables by the primary key.
 */
interface SitemapProductSource
{
    /**
     * The ids at positions N + 1, 2N + 1, … of all the products (drafts too) in the order of the id: the starts of
     * the chunks after the first one. No id when there are at most N products.
     *
     * @return list<string> lower-case UUIDs in ascending order
     */
    public function boundaries(int $chunkSize): array;

    /**
     * The published products of the range of ids, in the order of the id. Reads a bounded number of rows whatever the
     * range is: the products beyond the limit are left out.
     *
     * @return list<array{slug: string, updatedAt: \DateTimeImmutable}>
     */
    public function publishedInRange(SitemapChunkKey $chunk): array;
}
