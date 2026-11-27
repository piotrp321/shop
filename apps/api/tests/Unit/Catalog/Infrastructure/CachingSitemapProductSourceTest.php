<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Port\SitemapChunkRead;
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
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter(), 3);

        self::assertSame(['a', 'b'], $source->boundaries(3));
        self::assertSame(['a', 'b'], $source->boundaries(3));
    }

    public function testBoundariesCutWithAnotherChunkSizeAreAskedForAgain(): void
    {
        $inner = $this->createMock(SitemapProductSource::class);
        $inner->expects(self::exactly(2))->method('boundaries')->willReturnMap([[3, ['a']], [4, ['b']]]);
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter(), 3);

        self::assertSame(['a'], $source->boundaries(3));
        self::assertSame(['b'], $source->boundaries(4));
    }

    public function testAnEmptyListOfBoundariesIsCachedToo(): void
    {
        $inner = $this->createMock(SitemapProductSource::class);
        $inner->expects(self::once())->method('boundaries')->willReturn([]);
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter(), 3);

        $source->boundaries(3);

        self::assertSame([], $source->boundaries(3));
    }

    public function testBoundariesExpireAfterOneHour(): void
    {
        $inner = $this->createStub(SitemapProductSource::class);
        $inner->method('boundaries')->willReturn(['a']);
        $cache = new ArrayAdapter();
        $source = new CachingSitemapProductSource($inner, $cache, 3);

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
        $inner->expects(self::exactly(2))->method('publishedInRange')->with($key)->willReturn(new SitemapChunkRead([], false));
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter(), 3);

        $source->publishedInRange($key);
        $source->publishedInRange($key);
    }

    public function testAChunkReadIsReturnedAsTheSourceGaveIt(): void
    {
        $key = SitemapChunkKey::between(null, null);
        $read = new SitemapChunkRead([['slug' => 'a', 'updatedAt' => new \DateTimeImmutable('2026-01-01')]], false);
        $inner = $this->createStub(SitemapProductSource::class);
        $inner->method('publishedInRange')->willReturn($read);
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter(), 3);

        self::assertSame($read, $source->publishedInRange($key));
    }

    public function testAChunkThatOutgrewTheRowLimitMakesTheNextCallCutTheBoundariesAgain(): void
    {
        $inner = $this->createMock(SitemapProductSource::class);
        $inner->expects(self::exactly(2))->method('boundaries')->with(3)->willReturn(['a']);
        $inner->method('publishedInRange')->willReturn(new SitemapChunkRead([], true));
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter(), 3);

        $source->boundaries(3);
        $source->boundaries(3);
        $source->publishedInRange(SitemapChunkKey::between(null, null));
        $source->boundaries(3);
    }

    public function testAChunkWithinTheRowLimitKeepsTheBoundaries(): void
    {
        $inner = $this->createMock(SitemapProductSource::class);
        $inner->expects(self::once())->method('boundaries')->willReturn(['a']);
        $inner->method('publishedInRange')->willReturn(new SitemapChunkRead([], false));
        $source = new CachingSitemapProductSource($inner, new ArrayAdapter(), 3);

        $source->boundaries(3);
        $source->publishedInRange(SitemapChunkKey::between(null, null));
        $source->boundaries(3);
    }
}
