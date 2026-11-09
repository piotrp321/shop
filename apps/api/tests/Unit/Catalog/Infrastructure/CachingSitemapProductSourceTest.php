<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Port\SitemapProductSource;
use App\Catalog\Application\Query\SitemapChunkKey;
use App\Catalog\Infrastructure\Persistence\Doctrine\CachingSitemapProductSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CachingSitemapProductSourceTest extends TestCase
{
    public function testTheSecondCallWithinTheTimeToLiveDoesNotAskTheSource(): void
    {
        $inner = $this->createMock(SitemapProductSource::class);
        $inner->expects(self::once())->method('boundaries')->with(3)->willReturn(['a', 'b']);
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter());

        self::assertSame(['a', 'b'], $source->boundaries(3));
        self::assertSame(['a', 'b'], $source->boundaries(3));
    }

    public function testBoundariesCutWithAnotherChunkSizeAreAskedForAgain(): void
    {
        $inner = $this->createMock(SitemapProductSource::class);
        $inner->expects(self::exactly(2))->method('boundaries')->willReturnMap([[3, ['a']], [4, ['b']]]);
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter());

        self::assertSame(['a'], $source->boundaries(3));
        self::assertSame(['b'], $source->boundaries(4));
    }

    public function testAnEmptyListOfBoundariesIsCachedToo(): void
    {
        $inner = $this->createMock(SitemapProductSource::class);
        $inner->expects(self::once())->method('boundaries')->willReturn([]);
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter());

        $source->boundaries(3);

        self::assertSame([], $source->boundaries(3));
    }

    public function testBoundariesExpireAfterOneHour(): void
    {
        $inner = $this->createStub(SitemapProductSource::class);
        $inner->method('boundaries')->willReturn(['a']);
        $cache = new ArrayAdapter();
        $source = new CachingSitemapProductSource($inner, $cache);

        $source->boundaries(3);

        // ArrayAdapter keeps the expiry in the metadata of the stored item.
        $expiry = $cache->getItem('sitemap_boundaries_3')->getMetadata()['expiry'] ?? null;
        self::assertIsFloat($expiry);
        self::assertEqualsWithDelta(time() + 3600, $expiry, 5);
    }

    public function testAChunkIsAlwaysReadFromTheSource(): void
    {
        $key = SitemapChunkKey::between(null, null);
        $inner = $this->createMock(SitemapProductSource::class);
        $inner->expects(self::exactly(2))->method('publishedInRange')->with($key)->willReturn([]);
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter());

        $source->publishedInRange($key);
        $source->publishedInRange($key);
    }
}
