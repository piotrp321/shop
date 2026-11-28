<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * The published products of one sitemap chunk as the source read them.
 */
final readonly class SitemapChunkRead
{
    /**
     * @param list<array{slug: string, updatedAt: \DateTimeImmutable}> $products   in the order of the id, at most the row limit
     * @param bool                                                     $overflowed the range held more rows than the limit, so the products beyond it were left out
     */
    public function __construct(
        public array $products,
        public bool $overflowed,
    ) {
    }
}
