<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Infrastructure;

use App\Pricing\Domain\Promotion\PromotionPriceChangeSource;
use App\Pricing\Domain\Promotion\PromotionPricesChanged;
use App\Pricing\Infrastructure\Storefront\InvalidateStorefrontOnPromotionChange;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use PHPUnit\Framework\TestCase;

final class InvalidateStorefrontOnPromotionChangeTest extends TestCase
{
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-000000000001';

    private SpyStorefrontCache $cache;

    protected function setUp(): void
    {
        $this->cache = new SpyStorefrontCache();
    }

    public function testInvalidatesTheProduct(): void
    {
        $this->consumer()(new PromotionPricesChanged(self::PRODUCT, PromotionPriceChangeSource::Promotion, new \DateTimeImmutable()));

        self::assertSame(['product:'.self::PRODUCT], $this->cache->calls);
    }

    public function testASeedChangeIsHandledWithoutInvalidating(): void
    {
        $this->consumer()(new PromotionPricesChanged(self::PRODUCT, PromotionPriceChangeSource::Seed, new \DateTimeImmutable()));

        self::assertSame([], $this->cache->calls);
    }

    public function testAFailedInvalidationIsRethrownForTheRetries(): void
    {
        $this->cache->failure = new StorefrontCacheFailure('The Varnish purge failed: HTTP 503.');

        $this->expectExceptionObject($this->cache->failure);

        $this->consumer()(new PromotionPricesChanged(self::PRODUCT, PromotionPriceChangeSource::Promotion, new \DateTimeImmutable()));
    }

    private function consumer(): InvalidateStorefrontOnPromotionChange
    {
        return new InvalidateStorefrontOnPromotionChange($this->cache);
    }
}
