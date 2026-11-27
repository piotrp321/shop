<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\SitemapChunkRead;
use App\Catalog\Application\Port\SitemapProductSource;
use App\Catalog\Application\Query\SitemapChunkKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Keeps the boundaries in the cache pool `cache.sitemap` for an hour, so the scan of the primary key runs at most once
 * an hour per instance, whoever asks and however often. `CacheInterface::get` lets one process compute a missing
 * value at a time. A chunk is not cached, but a chunk that has outgrown the row limit since the cut (its products
 * beyond the limit are left out of the read) drops the boundaries, so the next list of chunks is cut again (G3).
 */
final readonly class CachingSitemapProductSource implements SitemapProductSource
{
    private const int TIME_TO_LIVE = 3600;
    private const string KEY_PREFIX = 'sitemap_boundaries_';

    public function __construct(
        private SitemapProductSource $inner,
        #[Autowire(service: 'cache.sitemap')]
        private CacheInterface $cache,
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
        if ($read->overflowed) {
            $this->cache->delete(self::KEY_PREFIX.$this->chunkSize);
        }

        return $read;
    }
}
