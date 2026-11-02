<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Port\CategoryNotFound;
use App\Pricing\Application\Port\DirectoryProduct;
use App\Pricing\Application\Port\InvalidPriceListCursor;
use App\Pricing\Application\Query\ListBackofficePrices;
use App\Pricing\Application\Query\ListBackofficePricesHandler;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRate;
use App\Tests\Unit\Pricing\InMemoryProductDirectory;
use App\Tests\Unit\Pricing\InMemoryProductPriceRepository;
use PHPUnit\Framework\TestCase;

final class ListBackofficePricesHandlerTest extends TestCase
{
    private const string BOOK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string SHOES = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string OUTDATED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';
    private const string UNPRICED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a63';

    private InMemoryProductPriceRepository $prices;
    private \DateTimeImmutable $updatedAt;

    protected function setUp(): void
    {
        $this->prices = new InMemoryProductPriceRepository();
        $this->updatedAt = new \DateTimeImmutable('2026-10-31 12:30:00+00:00');
        $this->store(self::BOOK, 5490, 'printed_book', 500, 3);
        $this->store(self::SHOES, 12300, 'standard', 2300, 1);
        // Stored at 8%, while the class is at 23% today.
        $this->store(self::OUTDATED, 10800, 'standard', 800, 2);
    }

    public function testListsTheProductsOfThePageWithTheirPricesAndTheCursorOfTheNextPage(): void
    {
        $directory = new InMemoryProductDirectory([
            $this->product(self::SHOES, 'Buty', 'standard'),
            $this->product(self::BOOK, 'Przewodnik', 'printed_book'),
        ], 'next-cursor');

        $list = $this->handler($directory)(new ListBackofficePrices(null, null, null, 25));

        self::assertSame('next-cursor', $list->next);
        self::assertCount(2, $list->items);
        [$shoes, $book] = $list->items;
        self::assertSame([self::SHOES, 'Buty', 'standard', 2300], [$shoes->productId, $shoes->name, $shoes->taxClass, $shoes->applicableVatRate]);
        self::assertNotNull($shoes->price);
        self::assertSame([12300, 10000, 2300, 2300, 'PLN', 1, false], [
            $shoes->price->gross, $shoes->price->net, $shoes->price->vat, $shoes->price->vatRate,
            $shoes->price->currency, $shoes->price->version, $shoes->price->vatRateOutdated,
        ]);
        self::assertEquals($this->updatedAt, $shoes->price->updatedAt);
        self::assertSame([self::BOOK, 'Przewodnik', 'printed_book', 500], [$book->productId, $book->name, $book->taxClass, $book->applicableVatRate]);
        self::assertSame([5490, 5229, 261, 500, 3], $book->price ? [$book->price->gross, $book->price->net, $book->price->vat, $book->price->vatRate, $book->price->version] : []);
    }

    public function testAProductWithoutAPriceHasNoPriceButStillAnApplicableRate(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::UNPRICED, 'Żel', 'energy_gel')]);

        $list = $this->handler($directory)(new ListBackofficePrices(null, null, null, 25));

        self::assertNull($list->next);
        self::assertSame(800, $list->items[0]->applicableVatRate);
        self::assertNull($list->items[0]->price);
    }

    public function testAPriceAtAnOutdatedRateIsMarkedAndTheApplicableRateIsTheCurrentOne(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::OUTDATED, 'Plecak', 'standard')]);

        $item = $this->handler($directory)(new ListBackofficePrices(null, null, null, 25))->items[0];

        self::assertSame(2300, $item->applicableVatRate);
        self::assertNotNull($item->price);
        self::assertSame(800, $item->price->vatRate);
        self::assertTrue($item->price->vatRateOutdated);
    }

    public function testTheApplicableRateFollowsTheCurrentClassOfTheProductNotTheClassOfThePrice(): void
    {
        // The price was stored for the class `standard`; Catalog says the product is a printed book now.
        $directory = new InMemoryProductDirectory([$this->product(self::SHOES, 'Buty', 'printed_book')]);

        $item = $this->handler($directory)(new ListBackofficePrices(null, null, null, 25))->items[0];

        self::assertSame('printed_book', $item->taxClass);
        self::assertSame(500, $item->applicableVatRate);
        self::assertNotNull($item->price);
        self::assertSame(2300, $item->price->vatRate);
    }

    public function testPricesOfThePageAreReadWithOneCall(): void
    {
        $directory = new InMemoryProductDirectory([
            $this->product(self::SHOES, 'A', 'standard'),
            $this->product(self::BOOK, 'B', 'printed_book'),
            $this->product(self::UNPRICED, 'C', 'standard'),
        ]);

        $this->handler($directory)(new ListBackofficePrices(null, null, null, 25));

        self::assertSame([[self::SHOES, self::BOOK, self::UNPRICED]], $this->prices->manyReads);
    }

    public function testAnEmptyPageReadsNoPrices(): void
    {
        $list = $this->handler(new InMemoryProductDirectory())(new ListBackofficePrices('xyz', null, null, 25));

        self::assertSame([], $list->items);
        self::assertNull($list->next);
        self::assertSame([], $this->prices->manyReads);
    }

    public function testPassesTheFiltersTheCursorAndThePageSizeToTheDirectory(): void
    {
        $directory = new InMemoryProductDirectory();

        $this->handler($directory)(new ListBackofficePrices('bie', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5aaa', 'cursor', 10));

        self::assertSame([['bie', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5aaa', 'cursor', 10]], $directory->requests);
    }

    public function testAnUnknownCategoryAndAnInvalidCursorAreNotSwallowed(): void
    {
        $missing = new InMemoryProductDirectory();
        $missing->categoryExists = false;
        try {
            $this->handler($missing)(new ListBackofficePrices(null, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5aaa', null, 25));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
            self::assertSame([], $this->prices->manyReads);
        }

        $invalid = new InMemoryProductDirectory();
        $invalid->cursorValid = false;
        $this->expectException(InvalidPriceListCursor::class);

        $this->handler($invalid)(new ListBackofficePrices(null, null, 'garbage', 25));
    }

    public function testAClassWithoutARateFailsTheListInsteadOfShowingAWrongRate(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::UNPRICED, 'Nowy', 'brand_new_class')]);

        $this->expectException(TaxClassWithoutRate::class);

        $this->handler($directory)(new ListBackofficePrices(null, null, null, 25));
    }

    private function handler(InMemoryProductDirectory $directory): ListBackofficePricesHandler
    {
        return new ListBackofficePricesHandler($directory, $this->prices, new PolishVatRates());
    }

    private function product(string $id, string $name, string $taxClass): DirectoryProduct
    {
        return new DirectoryProduct(ProductId::fromString($id), $name, TaxClass::fromString($taxClass));
    }

    private function store(string $id, int $gross, string $taxClass, int $basisPoints, int $version): void
    {
        $this->prices->store(ProductPrice::reconstitute(
            ProductId::fromString($id),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString($taxClass),
            $version,
            $this->updatedAt,
        ));
    }
}
