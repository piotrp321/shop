<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Infrastructure\Storefront\InvalidateStorefrontOnProductChange;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class InvalidateStorefrontOnProductChangeTest extends TestCase
{
    private const string PRODUCT = '0192F0C4-7C1A-7C4E-9A6E-000000000001';

    private SpyStorefrontCache $cache;

    protected function setUp(): void
    {
        $this->cache = new SpyStorefrontCache();
    }

    /**
     * One invalidation of both tags: the pages and lists with the product, and the 404 of its slug (a page visited
     * before the publication).
     */
    public function testInvalidatesTheTagOfTheProductAndTheTagOfItsSlug(): void
    {
        $this->consumer()($this->event('kurtka-puchowa'));

        self::assertSame(['tags:product-0192f0c4-7c1a-7c4e-9a6e-000000000001 product-slug-kurtka-puchowa'], $this->cache->calls);
    }

    public function testTheSameEventAgainInvalidatesTheSameTagsAgain(): void
    {
        $this->consumer()($this->event('kurtka-puchowa'));
        $this->consumer()($this->event('kurtka-puchowa'));

        self::assertSame(array_fill(0, 2, 'tags:product-0192f0c4-7c1a-7c4e-9a6e-000000000001 product-slug-kurtka-puchowa'), $this->cache->calls);
    }

    public function testAFailedInvalidationIsRethrownForTheRetries(): void
    {
        $this->cache->failure = new StorefrontCacheFailure('The Varnish purge failed: HTTP 503.');

        $this->expectExceptionObject($this->cache->failure);

        $this->consumer()($this->event('kurtka-puchowa'));
    }

    /**
     * Catalog publishes only slugs and ids it accepted, so a malformed one is an inconsistency a retry cannot fix.
     */
    public function testAMalformedSlugOrIdGoesToFailedWithoutAnInvalidation(): void
    {
        foreach ([$this->event('Kurtka puchowa'), new ProductChanged('not-a-uuid', 'kurtka', new \DateTimeImmutable())] as $event) {
            try {
                $this->consumer()($event);
                self::fail('Expected UnrecoverableMessageHandlingException.');
            } catch (UnrecoverableMessageHandlingException $e) {
                self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            }
        }

        self::assertSame([], $this->cache->calls);
    }

    private function consumer(): InvalidateStorefrontOnProductChange
    {
        return new InvalidateStorefrontOnProductChange($this->cache);
    }

    private function event(string $slug): ProductChanged
    {
        return new ProductChanged(self::PRODUCT, $slug, new \DateTimeImmutable('2026-11-07T10:00:00+00:00'));
    }
}
