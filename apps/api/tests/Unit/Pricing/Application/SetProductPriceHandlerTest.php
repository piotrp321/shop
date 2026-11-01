<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Command\SetProductPrice;
use App\Pricing\Application\Command\SetProductPriceHandler;
use App\Pricing\Application\Query\ListedPrice;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\GrossPriceOutOfRange;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductNotFound;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRate;
use App\Tests\Unit\Pricing\InMemoryProductPriceRepository;
use App\Tests\Unit\Pricing\InMemoryProductTaxClasses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SetProductPriceHandlerTest extends TestCase
{
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';

    private InMemoryProductPriceRepository $prices;
    private InMemoryProductTaxClasses $taxClasses;
    private SetProductPriceHandler $handler;

    protected function setUp(): void
    {
        $this->prices = new InMemoryProductPriceRepository();
        $this->taxClasses = new InMemoryProductTaxClasses([self::PRODUCT => 'standard']);
        $this->handler = new SetProductPriceHandler($this->prices, $this->taxClasses, new PolishVatRates());
    }

    public function testFirstPriceIsCreatedAtTheRateOfTheProductClass(): void
    {
        ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 12300, null, 'sub-1', 'Anna Admin'));

        $price = $this->price();
        self::assertSame(1, $price->version());
        self::assertSame('standard', $price->taxClass()->toString());
        self::assertSame(10000, $price->breakdown()->net()->amount());
        self::assertSame(2300, $price->breakdown()->vat()->amount());
        self::assertCount(1, $this->prices->history);
        $origin = $this->prices->history[0]->origin();
        self::assertSame(PriceChangeSource::Staff, $origin->source());
        self::assertSame('sub-1', $origin->actorId());
        self::assertSame('Anna Admin', $origin->actorName());
    }

    public function testReturnsTheSavedFirstPrice(): void
    {
        $saved = ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 12300, null, 'sub-1', 'Anna Admin'));

        self::assertEquals(
            new ListedPrice(12300, 10000, 2300, 2300, 'PLN', 1, $this->price()->updatedAt(), false),
            $saved,
        );
    }

    public function testReturnsTheChangedPriceWithItsNewVersion(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        $saved = ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 12300, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(3, $saved->version);
        self::assertSame([12300, 10000, 2300, 2300], [$saved->gross, $saved->net, $saved->vat, $saved->vatRate]);
        self::assertEquals($this->price()->updatedAt(), $saved->updatedAt);
        self::assertFalse($saved->vatRateOutdated);
    }

    public function testSameAmountReturnsTheStoredPriceUnchanged(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        $saved = ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 10000, 2, 'sub-1', 'Anna Admin'));

        self::assertEquals(
            new ListedPrice(10000, 8130, 1870, 2300, 'PLN', 2, new \DateTimeImmutable('2026-10-01 12:00:00+00:00'), false),
            $saved,
        );
    }

    public function testFixingAnOutdatedRateReturnsTheCurrentRate(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 800, version: 2);

        $saved = ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 10000, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(2300, $saved->vatRate);
        self::assertSame(3, $saved->version);
        self::assertFalse($saved->vatRateOutdated);
    }

    public function testSeedPriceHasNoActor(): void
    {
        ($this->handler)(SetProductPrice::bySeed(self::PRODUCT, 5490));

        self::assertCount(1, $this->prices->history);
        $origin = $this->prices->history[0]->origin();
        self::assertSame(PriceChangeSource::Seed, $origin->source());
        self::assertNull($origin->actorId());
        self::assertNull($origin->actorName());
    }

    public function testUnknownProductIsNotFoundAndNothingIsStored(): void
    {
        try {
            ($this->handler)(SetProductPrice::byStaff(self::UNKNOWN, 100, null, 'sub-1', 'Anna Admin'));
            self::fail('Expected ProductNotFound.');
        } catch (ProductNotFound) {
        }

        self::assertNull($this->prices->find(ProductId::fromString(self::UNKNOWN)));
        self::assertSame(0, $this->prices->writes);
    }

    public function testExpectedVersionForAProductWithoutPriceIsAConflict(): void
    {
        try {
            ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 100, 1, 'sub-1', 'Anna Admin'));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        self::assertNull($this->prices->find(ProductId::fromString(self::PRODUCT)));
        self::assertSame(0, $this->prices->writes);
    }

    #[DataProvider('wrongVersions')]
    public function testWrongOrMissingVersionForAnExistingPriceIsAConflictAndChangesNothing(?int $expectedVersion): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        try {
            ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 20000, $expectedVersion, 'sub-1', 'Anna Admin'));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        self::assertSame(2, $this->price()->version());
        self::assertSame(10000, $this->price()->breakdown()->gross()->amount());
        self::assertSame(0, $this->prices->writes);
    }

    /** @return iterable<string, array{int|null}> */
    public static function wrongVersions(): iterable
    {
        yield 'null' => [null];
        yield 'older' => [1];
        yield 'newer' => [3];
    }

    public function testSameAmountRecordsNothingAndKeepsTheVersion(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 10000, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(2, $this->price()->version());
        self::assertSame([], $this->prices->history);
        self::assertSame(0, $this->prices->writes);
    }

    public function testNewAmountRecordsOneEntryAndANewVersion(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 12300, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(3, $this->price()->version());
        self::assertSame(12300, $this->price()->breakdown()->gross()->amount());
        self::assertCount(1, $this->prices->history);
        self::assertSame(12300, $this->prices->history[0]->breakdown()->gross()->amount());
    }

    public function testSameAmountFixesAnOutdatedRateWithOneEntry(): void
    {
        // Stored at 8% while the rate of `standard` is 23% today.
        $this->storePrice(gross: 10000, basisPoints: 800, version: 2);

        ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 10000, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(3, $this->price()->version());
        self::assertSame(2300, $this->price()->breakdown()->rate()->basisPoints());
        self::assertCount(1, $this->prices->history);
    }

    public function testExistingPriceKeepsItsOwnClassEvenWhenCatalogNowSaysOtherwise(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 1);
        $handler = new SetProductPriceHandler($this->prices, new InMemoryProductTaxClasses([self::PRODUCT => 'printed_book']), new PolishVatRates());

        $handler(SetProductPrice::byStaff(self::PRODUCT, 12300, 1, 'sub-1', 'Anna Admin'));

        self::assertSame('standard', $this->price()->taxClass()->toString());
        self::assertSame(2300, $this->price()->breakdown()->rate()->basisPoints());
    }

    public function testGrossOutOfRangeIsRejectedBeforeAnythingIsRead(): void
    {
        try {
            ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 0, null, 'sub-1', 'Anna Admin'));
            self::fail('Expected GrossPriceOutOfRange.');
        } catch (GrossPriceOutOfRange) {
        }

        self::assertSame([], $this->taxClasses->asked);
        self::assertSame(0, $this->prices->writes);
    }

    public function testProductClassWithoutRateIsRejected(): void
    {
        $handler = new SetProductPriceHandler($this->prices, new InMemoryProductTaxClasses([self::PRODUCT => 'luxury']), new PolishVatRates());

        $this->expectException(TaxClassWithoutRate::class);

        $handler(SetProductPrice::byStaff(self::PRODUCT, 100, null, 'sub-1', 'Anna Admin'));
    }

    public function testStaffChangeWithoutActorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ($this->handler)(SetProductPrice::byStaff(self::PRODUCT, 100, null, '', ''));
    }

    private function storePrice(int $gross, int $basisPoints, int $version): void
    {
        $this->prices->store(ProductPrice::reconstitute(
            ProductId::fromString(self::PRODUCT),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString('standard'),
            $version,
            new \DateTimeImmutable('2026-10-01 12:00:00+00:00'),
        ));
    }

    private function price(): ProductPrice
    {
        return $this->prices->find(ProductId::fromString(self::PRODUCT)) ?? throw new \LogicException('No price stored.');
    }
}
