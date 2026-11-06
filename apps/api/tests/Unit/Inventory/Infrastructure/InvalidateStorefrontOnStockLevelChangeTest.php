<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure;

use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockLevelChanged;
use App\Inventory\Infrastructure\Storefront\InvalidateStorefrontOnStockLevelChange;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;
use App\Tests\Support\InMemory\InMemoryCatalogVariants;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvalidateStorefrontOnStockLevelChangeTest extends TestCase
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

    /**
     * @return iterable<string, array{StockChangeSource}>
     */
    public static function invalidatingSources(): iterable
    {
        yield 'staff' => [StockChangeSource::Staff];
        yield 'system' => [StockChangeSource::System];
    }

    #[DataProvider('invalidatingSources')]
    public function testInvalidatesTheProductOfTheVariant(StockChangeSource $source): void
    {
        $this->consumer()(new StockLevelChanged(self::VARIANT, AvailabilityLevel::InStock, $source, new \DateTimeImmutable()));

        self::assertSame([self::VARIANT], $this->catalog->asked);
        self::assertSame(['product:'.self::PRODUCT], $this->cache->calls);
    }

    public function testAVariantUnknownToCatalogIsHandledWithoutInvalidation(): void
    {
        $this->consumer()(new StockLevelChanged('0192f0c4-7c1a-7c4e-9a6e-0000000000ff', AvailabilityLevel::OutOfStock, StockChangeSource::Staff, new \DateTimeImmutable()));

        self::assertSame([], $this->cache->calls);
    }

    public function testASeedChangeIsHandledWithoutAskingCatalogOrInvalidating(): void
    {
        $this->consumer()(new StockLevelChanged(self::VARIANT, AvailabilityLevel::InStock, StockChangeSource::Seed, new \DateTimeImmutable()));

        self::assertSame([], $this->catalog->asked);
        self::assertSame([], $this->cache->calls);
    }

    public function testAFailedInvalidationIsRethrownForTheRetries(): void
    {
        $this->cache->failure = new StorefrontCacheFailure('The store revalidation failed: HTTP 503.');

        $this->expectExceptionObject($this->cache->failure);

        $this->consumer()(new StockLevelChanged(self::VARIANT, AvailabilityLevel::LowStock, StockChangeSource::System, new \DateTimeImmutable()));
    }

    private function consumer(): InvalidateStorefrontOnStockLevelChange
    {
        return new InvalidateStorefrontOnStockLevelChange($this->catalog->queryBus(), $this->cache);
    }
}
