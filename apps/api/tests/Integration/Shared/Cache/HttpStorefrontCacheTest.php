<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Cache;

use App\Shared\Infrastructure\Http\Cache\HttpStorefrontCache;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * HttpStorefrontCache against a real HTTP double for Varnish and the store: the requests, their order, and every
 * failure as an exception.
 */
final class HttpStorefrontCacheTest extends TestCase
{
    private const string PRODUCT = '0192F0C4-7C1A-7C4E-9A6E-3F1D2B4C5A60';
    private const string PURGE_SECRET = 'purge-secret';
    private const string REVALIDATE_SECRET = 'revalidate-secret';

    private ?HttpDouble $double = null;

    protected function tearDown(): void
    {
        $this->double?->stop();
    }

    public function testInvalidatingAProductPurgesItsTagInVarnishThenRevalidatesItInTheStore(): void
    {
        $this->double = HttpDouble::start();

        $this->cache($this->double->url, $this->double->url)->invalidateProduct(self::PRODUCT);

        $requests = $this->double->requests();
        self::assertSame(['varnish', 'store'], array_column($requests, 'side'));
        self::assertSame('PURGE', $requests[0]['method']);
        self::assertSame('/', $requests[0]['path']);
        self::assertSame('product-0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', $requests[0]['headers']['xkey-purge']);
        self::assertSame(self::PURGE_SECRET, $requests[0]['headers']['x-purge-token']);
        self::assertSame('POST', $requests[1]['method']);
        self::assertSame('/api/revalidate', $requests[1]['path']);
        self::assertSame('Bearer '.self::REVALIDATE_SECRET, $requests[1]['headers']['authorization']);
        self::assertStringStartsWith('application/json', $requests[1]['headers']['content-type']);
        self::assertSame(['tags' => ['product-0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60']], json_decode($requests[1]['body'], true));
        self::assertArrayNotHasKey('x-purge-token', $requests[1]['headers']);
        self::assertArrayNotHasKey('authorization', $requests[0]['headers']);
    }

    public function testInvalidatingTagsPurgesAllOfThemInVarnishThenRevalidatesAllOfThemInTheStore(): void
    {
        $this->double = HttpDouble::start();

        $this->cache($this->double->url, $this->double->url)->invalidateTags(['product-0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', 'product-slug-buty-puls']);

        $requests = $this->double->requests();
        self::assertSame(['varnish', 'store'], array_column($requests, 'side'));
        self::assertSame('PURGE', $requests[0]['method']);
        self::assertSame('product-0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60 product-slug-buty-puls', $requests[0]['headers']['xkey-purge']);
        self::assertSame(self::PURGE_SECRET, $requests[0]['headers']['x-purge-token']);
        self::assertSame('/api/revalidate', $requests[1]['path']);
        self::assertSame(['tags' => ['product-0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', 'product-slug-buty-puls']], json_decode($requests[1]['body'], true));
    }

    public function testAFailedPurgeOfTagsStopsTheRevalidation(): void
    {
        $double = $this->double = HttpDouble::start(['varnish' => ['statuses' => [500]]]);

        $this->assertFailure(fn () => $this->cache($double->url, $double->url)->invalidateTags(['product-slug-buty-puls']), 'Varnish');

        self::assertSame(['varnish'], $double->sides());
    }

    /**
     * Tags come from CacheTags: no tag, or a tag outside its alphabet (a space would purge a second tag, a line break
     * would add a header), is a programming error and sends nothing.
     */
    public function testNoTagOrATagOutsideTheAlphabetOfCacheTagsIsRefusedBeforeAnyRequest(): void
    {
        $double = $this->double = HttpDouble::start();
        $cache = $this->cache($double->url, $double->url);

        foreach ([[], ['catalog other'], ["catalog\r\nX-Evil: 1"], [''], ['Catalog']] as $tags) {
            try {
                $cache->invalidateTags($tags);
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $double->sides());
    }

    public function testTheWholeCatalogIsTheCatalogTagInEachLayer(): void
    {
        $this->double = HttpDouble::start();
        $cache = $this->cache($this->double->url, $this->double->url);

        $cache->purgeAll();
        $cache->revalidateAll();

        $requests = $this->double->requests();
        self::assertSame(['varnish', 'store'], array_column($requests, 'side'));
        self::assertSame('catalog', $requests[0]['headers']['xkey-purge']);
        self::assertSame(['tags' => ['catalog']], json_decode($requests[1]['body'], true));
    }

    public function testAFailedPurgeIsAnExceptionAndStopsTheRevalidation(): void
    {
        $double = $this->double = HttpDouble::start(['varnish' => ['statuses' => [403]]]);

        $this->assertFailure(fn () => $this->cache($double->url, $double->url)->invalidateProduct(self::PRODUCT), 'Varnish');

        self::assertSame(['varnish'], $double->sides());
    }

    public function testAnUnreachableVarnishIsAnExceptionAndStopsTheRevalidation(): void
    {
        $double = $this->double = HttpDouble::start();

        $this->assertFailure(fn () => $this->cache(HttpDouble::refusingUrl(), $double->url)->invalidateProduct(self::PRODUCT), 'Varnish');

        self::assertSame([], $double->sides());
    }

    public function testAFailedRevalidationIsAnException(): void
    {
        $double = $this->double = HttpDouble::start(['store' => ['statuses' => [503]]]);

        $this->assertFailure(fn () => $this->cache($double->url, $double->url)->invalidateProduct(self::PRODUCT), 'store');

        self::assertSame(['varnish', 'store'], $double->sides());
    }

    public function testAnAnswerOtherThan200IsAFailureToo(): void
    {
        $double = $this->double = HttpDouble::start(['store' => ['statuses' => [204]]]);

        $this->assertFailure(fn () => $this->cache($double->url, $double->url)->revalidateAll(), 'store');
    }

    public function testAStoreAnsweringPastItsLimitIsAnException(): void
    {
        $double = $this->double = HttpDouble::start(['store' => ['delay' => 3]]);
        $started = microtime(true);

        $this->assertFailure(fn () => $this->cache($double->url, $double->url, storeTimeout: 1.0)->invalidateProduct(self::PRODUCT), 'store');

        self::assertLessThan(2.5, microtime(true) - $started);
    }

    public function testAVarnishAnsweringPastItsLimitIsAnException(): void
    {
        $double = $this->double = HttpDouble::start(['varnish' => ['delay' => 3]]);
        $started = microtime(true);

        $this->assertFailure(fn () => $this->cache($double->url, $double->url, varnishTimeout: 1.0)->invalidateProduct(self::PRODUCT), 'Varnish');

        self::assertLessThan(2.5, microtime(true) - $started);
    }

    /**
     * The first request of the route in `next dev` compiles it: a store answering after a few seconds is not a
     * failure with the default limits, although the application's HTTP client stops after 5 s by default. The test
     * scales that down by the same factor: the store answers after 3 s, past both the idle timeout (1 s) and the
     * maximum duration (2 s) of the client, and the default store limit (30 s) still holds the request.
     */
    public function testAStoreAnsweringAfterAFewSecondsFitsTheDefaultLimit(): void
    {
        $this->double = HttpDouble::start(['store' => ['delay' => 3]]);
        $client = HttpClient::create(['timeout' => 1, 'max_duration' => 2]);

        new HttpStorefrontCache($client, $this->double->url, self::PURGE_SECRET, $this->double->url, self::REVALIDATE_SECRET)->invalidateProduct(self::PRODUCT);

        self::assertSame(['varnish', 'store'], $this->double->sides());
    }

    private function cache(string $varnishUrl, string $storeUrl, float $varnishTimeout = 5.0, float $storeTimeout = 30.0): HttpStorefrontCache
    {
        return new HttpStorefrontCache(HttpClient::create(), $varnishUrl, self::PURGE_SECRET, $storeUrl, self::REVALIDATE_SECRET, $varnishTimeout, $storeTimeout);
    }

    private function assertFailure(\Closure $call, string $layer): void
    {
        try {
            $call();
            self::fail('No exception.');
        } catch (StorefrontCacheFailure $failure) {
            self::assertStringContainsString($layer, $failure->getMessage());
            self::assertStringNotContainsString(self::PURGE_SECRET, $failure->getMessage());
            self::assertStringNotContainsString(self::REVALIDATE_SECRET, $failure->getMessage());
        }
    }
}
