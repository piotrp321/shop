<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class SitemapChunk
{
    /**
     * @param string $key opaque text that names the chunk in the product query
     */
    public function __construct(public string $key)
    {
    }
}
