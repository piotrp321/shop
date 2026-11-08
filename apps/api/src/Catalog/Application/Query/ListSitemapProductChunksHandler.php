<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\SitemapProductSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListSitemapProductChunksHandler
{
    public function __construct(
        private SitemapProductSource $source,
        private int $chunkSize,
    ) {
    }

    /**
     * Chunk i runs from boundary i to boundary i + 1; the first has no start and the last no end, so a product
     * created after the boundaries were taken belongs to a chunk whatever its id is.
     */
    public function __invoke(ListSitemapProductChunks $query): SitemapChunkList
    {
        $boundaries = $this->source->boundaries($this->chunkSize);
        $starts = [null, ...$boundaries];
        $ends = [...$boundaries, null];

        $chunks = [];
        foreach ($starts as $index => $start) {
            $chunks[] = new SitemapChunk(SitemapChunkKey::between($start, $ends[$index])->encode());
        }

        return new SitemapChunkList($chunks);
    }
}
