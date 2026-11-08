<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The published products of one chunk of the product sitemap, named by the key that the chunk list issued; the answer
 * is a SitemapProductList. A key that was not issued throws InvalidSitemapChunk.
 */
final readonly class ListSitemapProducts
{
    public function __construct(public string $chunk)
    {
    }
}
