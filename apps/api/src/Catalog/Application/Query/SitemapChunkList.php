<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class SitemapChunkList
{
    /**
     * @param list<SitemapChunk> $chunks in the order of the product id; never empty
     */
    public function __construct(public array $chunks)
    {
    }
}
