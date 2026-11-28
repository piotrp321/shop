<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\SitemapChunkRead;
use App\Catalog\Application\Port\SitemapProductSource;
use App\Catalog\Application\Query\SitemapChunkKey;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Keeps the boundaries in the cache pool `cache.sitemap` for an hour, so the scan of the primary key runs at most once
 * an hour per instance, whoever asks and however often. `CacheInterface::get` lets one process compute a missing
 * value at a time. A chunk is not cached, but a chunk of the stored cut that has outgrown the row limit since the cut
 * (its products beyond the limit are left out of the read) drops the boundaries, so the next list of chunks is cut again
 * (G3). Only such a chunk does: the key of a chunk comes from the caller, so a key made by hand or issued by an older cut
 * must not be able to drop the boundaries (the scan runs at most once an hour whoever asks), and reading the stored
 * boundaries never cuts them.
 */
final readonly class CachingSitemapProductSource implements SitemapProductSource
{
    private const int TIME_TO_LIVE = 3600;
    private const string KEY_PREFIX = 'sitemap_boundaries_';

    public function __construct(
        private SitemapProductSource $inner,
        #[Autowire(service: 'cache.sitemap')]
        private CacheInterface&CacheItemPoolInterface $cache,
        private int $chunkSize,
    ) {
    }

    public function boundaries(int $chunkSize): array
    {
        /** @var list<string> $boundaries */
        $boundaries = $this->cache->get(self::KEY_PREFIX.$chunkSize, function (ItemInterface $item) use ($chunkSize): array {
            $item->expiresAfter(self::TIME_TO_LIVE);

            return $this->inner->boundaries($chunkSize);
        });

        return $boundaries;
    }

    public function publishedInRange(SitemapChunkKey $chunk): SitemapChunkRead
    {
        $read = $this->inner->publishedInRange($chunk);
        if ($read->overflowed && $this->isAChunkOfTheStoredCut($chunk)) {
            $this->cache->delete(self::KEY_PREFIX.$this->chunkSize);
        }

        return $read;
    }

    private function isAChunkOfTheStoredCut(SitemapChunkKey $chunk): bool
    {
        $item = $this->cache->getItem(self::KEY_PREFIX.$this->chunkSize);
        if (!$item->isHit()) {
            return false;
        }
        /** @var list<string> $boundaries */
        $boundaries = $item->get();
        // Chunk i runs from boundary i - 1 to boundary i: the first has no start and the last no end.
        $starts = [null, ...$boundaries];
        $ends = [...$boundaries, null];
        foreach ($starts as $index => $start) {
            if ($start === $chunk->from && $ends[$index] === $chunk->to) {
                return true;
            }
        }

        return false;
    }
}
