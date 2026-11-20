<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Infrastructure;

use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\VariantPriceChanged;
use App\Pricing\Infrastructure\Storefront\InvalidateStorefrontOnPriceChange;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;
use App\Tests\Support\InMemory\InMemoryCatalogVariants;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use PHPUnit\Framework\TestCase;

final class InvalidateStorefrontOnPriceChangeTest extends TestCase
{
    private const string VARIANT = '0192f0c4-7c1a-7c4e-9a6e-000000000011';
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-000000000001';

    private InMemoryCatalogVariants $catalog;
    private SpyStorefrontCache $cache;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryCatalogVariants([self::VARIANT => self::PRODUCT]);
        $this->cache = new SpyStorefrontCache();
    }

    public function testInvalidatesTheProductOfTheVariant(): void
    {
        $this->consumer()(new VariantPriceChanged(self::VARIANT, PriceChangeSource::Staff, new \DateTimeImmutable()));

        self::assertSame([self::VARIANT], $this->catalog->asked);
        self::assertSame(['product:'.self::PRODUCT], $this->cache->calls);
    }

    /**
     * The gross price and the history do not change in a reconciliation, and the pages hold neither the rate nor the net.
     */
    public function testATaxReconciliationIsHandledWithoutAskingCatalogOrInvalidating(): void
    {
        $this->consumer()(new VariantPriceChanged(self::VARIANT, PriceChangeSource::TaxReconciliation, new \DateTimeImmutable()));

        self::assertSame([], $this->catalog->asked);
        self::assertSame([], $this->cache->calls);
    }

    public function testAVariantUnknownToCatalogIsHandledWithoutInvalidation(): void
    {
        $this->consumer()(new VariantPriceChanged('0192f0c4-7c1a-7c4e-9a6e-0000000000ff', PriceChangeSource::Staff, new \DateTimeImmutable()));

        self::assertSame([], $this->cache->calls);
    }

    public function testASeedChangeIsHandledWithoutAskingCatalogOrInvalidating(): void
    {
        $this->consumer()(new VariantPriceChanged(self::VARIANT, PriceChangeSource::Seed, new \DateTimeImmutable()));

        self::assertSame([], $this->catalog->asked);
        self::assertSame([], $this->cache->calls);
    }

    public function testAFailedInvalidationIsRethrownForTheRetries(): void
    {
        $this->cache->failure = new StorefrontCacheFailure('The Varnish purge failed: HTTP 503.');

        $this->expectExceptionObject($this->cache->failure);

        $this->consumer()(new VariantPriceChanged(self::VARIANT, PriceChangeSource::Staff, new \DateTimeImmutable()));
    }

    private function consumer(): InvalidateStorefrontOnPriceChange
    {
        return new InvalidateStorefrontOnPriceChange($this->catalog->queryBus(), $this->cache);
    }
}
