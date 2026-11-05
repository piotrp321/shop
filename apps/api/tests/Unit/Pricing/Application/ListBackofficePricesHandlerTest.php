<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Port\CategoryNotFound;
use App\Pricing\Application\Port\DirectoryProduct;
use App\Pricing\Application\Port\InvalidPriceListCursor;
use App\Pricing\Application\Query\ListBackofficePrices;
use App\Pricing\Application\Query\ListBackofficePricesHandler;
use App\Pricing\Application\Query\PriceListItem;
use App\Pricing\Application\Query\PriceListVariant;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRate;
use App\Tests\Unit\Pricing\InMemoryProductDirectory;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use PHPUnit\Framework\TestCase;

final class ListBackofficePricesHandlerTest extends TestCase
{
    private const string BOOK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string SHOES = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string GEL = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a63';

    // Variant ids are never the ids of their products.
    private const string BOOK_SOFT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01';
    private const string BOOK_HARD = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02';
    private const string SHOES_40 = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03';
    private const string SHOES_41 = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b04';
    private const string SHOES_42 = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b05';
    private const string GEL_DEFAULT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b06';

    private InMemoryVariantPriceRepository $prices;
    private \DateTimeImmutable $updatedAt;

    protected function setUp(): void
    {
        $this->prices = new InMemoryVariantPriceRepository();
        $this->updatedAt = new \DateTimeImmutable('2026-10-31 12:30:00+00:00');
        $this->store(self::BOOK_SOFT, 4490, 'printed_book', 500, 3);
        $this->store(self::BOOK_HARD, 5990, 'printed_book', 500, 1);
        $this->store(self::SHOES_40, 12300, 'standard', 2300, 1);
        // Stored at 8%, while the class is at 23% today.
        $this->store(self::SHOES_42, 10800, 'standard', 800, 2);
    }

    public function testListsTheProductsOfThePageWithTheirVariantsInOrderAndTheirPrices(): void
    {
        $directory = new InMemoryProductDirectory([
            $this->product(self::SHOES, 'Buty', 'standard'),
            $this->product(self::BOOK, 'Przewodnik', 'printed_book'),
        ], 'next-cursor');
        $directory->withVariants(self::SHOES, [self::SHOES_40 => 'czarne, 40', self::SHOES_41 => 'czarne, 41', self::SHOES_42 => 'czarne, 42']);
        $directory->withVariants(self::BOOK, [self::BOOK_HARD => 'oprawa twarda', self::BOOK_SOFT => 'oprawa miękka']);

        $list = $this->handler($directory)(new ListBackofficePrices(null, null, null, 25));

        self::assertSame('next-cursor', $list->next);
        self::assertCount(2, $list->items);
        [$shoes, $book] = $list->items;
        self::assertSame([self::SHOES, 'Buty', 'standard', 2300], [$shoes->productId, $shoes->name, $shoes->taxClass, $shoes->applicableVatRate]);
        self::assertSame(
            [[self::SHOES_40, 'czarne, 40', 12300], [self::SHOES_41, 'czarne, 41', null], [self::SHOES_42, 'czarne, 42', 10800]],
            array_map(static fn (PriceListVariant $variant): array => [$variant->variantId, $variant->label, $variant->price?->gross], $shoes->variants),
        );
        $price = $shoes->variants[0]->price;
        self::assertNotNull($price);
        self::assertSame([12300, 10000, 2300, 2300, 'PLN', 1, false], [
            $price->gross, $price->net, $price->vat, $price->vatRate, $price->currency, $price->version, $price->vatRateOutdated,
        ]);
        self::assertEquals($this->updatedAt, $price->updatedAt);
        self::assertSame([self::BOOK, 'Przewodnik', 'printed_book', 500], [$book->productId, $book->name, $book->taxClass, $book->applicableVatRate]);
        self::assertSame(
            [[self::BOOK_HARD, 'oprawa twarda', 5990, 1], [self::BOOK_SOFT, 'oprawa miękka', 4490, 3]],
            array_map(static fn (PriceListVariant $variant): array => [$variant->variantId, $variant->label, $variant->price?->gross, $variant->price?->version], $book->variants),
        );
    }

    public function testTheDefaultVariantHasAnEmptyLabelAndAVariantWithoutAPriceStillHasTheApplicableRate(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::GEL, 'Żel', 'energy_gel')]);
        $directory->withVariants(self::GEL, [self::GEL_DEFAULT => '']);

        $list = $this->handler($directory)(new ListBackofficePrices(null, null, null, 25));

        self::assertNull($list->next);
        self::assertSame(800, $list->items[0]->applicableVatRate);
        self::assertCount(1, $list->items[0]->variants);
        self::assertSame([self::GEL_DEFAULT, ''], [$list->items[0]->variants[0]->variantId, $list->items[0]->variants[0]->label]);
        self::assertNull($list->items[0]->variants[0]->price);
    }

    public function testAPriceAtAnOutdatedRateIsMarkedAndTheApplicableRateIsTheCurrentOne(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::SHOES, 'Buty', 'standard')]);
        $directory->withVariants(self::SHOES, [self::SHOES_42 => 'czarne, 42']);

        $item = $this->handler($directory)(new ListBackofficePrices(null, null, null, 25))->items[0];

        self::assertSame(2300, $item->applicableVatRate);
        $price = $item->variants[0]->price;
        self::assertNotNull($price);
        self::assertSame(800, $price->vatRate);
        self::assertTrue($price->vatRateOutdated);
    }

    public function testTheApplicableRateFollowsTheCurrentClassOfTheProductNotTheClassOfThePrice(): void
    {
        // The price was stored for the class `standard`; Catalog says the product is a printed book now.
        $directory = new InMemoryProductDirectory([$this->product(self::SHOES, 'Buty', 'printed_book')]);
        $directory->withVariants(self::SHOES, [self::SHOES_40 => 'czarne, 40']);

        $item = $this->handler($directory)(new ListBackofficePrices(null, null, null, 25))->items[0];

        self::assertSame('printed_book', $item->taxClass);
        self::assertSame(500, $item->applicableVatRate);
        self::assertSame(2300, $item->variants[0]->price?->vatRate);
    }

    public function testTheVariantsAndThePricesOfThePageAreReadWithOneCallEach(): void
    {
        $directory = new InMemoryProductDirectory([
            $this->product(self::SHOES, 'A', 'standard'),
            $this->product(self::BOOK, 'B', 'printed_book'),
            $this->product(self::GEL, 'C', 'energy_gel'),
        ]);
        $directory->withVariants(self::SHOES, [self::SHOES_40 => '40', self::SHOES_41 => '41']);
        $directory->withVariants(self::BOOK, [self::BOOK_SOFT => 'miękka']);
        $directory->withVariants(self::GEL, [self::GEL_DEFAULT => '']);

        $this->handler($directory)(new ListBackofficePrices(null, null, null, 25));

        self::assertSame([[self::SHOES, self::BOOK, self::GEL]], $directory->variantRequests);
        self::assertSame([[self::SHOES_40, self::SHOES_41, self::BOOK_SOFT, self::GEL_DEFAULT]], $this->prices->manyReads);
    }

    /**
     * A product removed from Catalog between the page and its variants has no rows to price.
     */
    public function testAProductWithoutVariantsIsListedWithoutRows(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::SHOES, 'Buty', 'standard'), $this->product(self::GEL, 'Żel', 'energy_gel')]);
        $directory->withVariants(self::GEL, [self::GEL_DEFAULT => '']);

        $list = $this->handler($directory)(new ListBackofficePrices(null, null, null, 25));

        self::assertSame([self::SHOES, self::GEL], array_map(static fn (PriceListItem $item): string => $item->productId, $list->items));
        self::assertSame([], $list->items[0]->variants);
        self::assertSame([[self::GEL_DEFAULT]], $this->prices->manyReads);
    }

    public function testAnEmptyPageReadsNoVariantsAndNoPrices(): void
    {
        $directory = new InMemoryProductDirectory();
        $list = $this->handler($directory)(new ListBackofficePrices('xyz', null, null, 25));

        self::assertSame([], $list->items);
        self::assertNull($list->next);
        self::assertSame([], $directory->variantRequests);
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
        $directory = new InMemoryProductDirectory([$this->product(self::GEL, 'Nowy', 'brand_new_class')]);
        $directory->withVariants(self::GEL, [self::GEL_DEFAULT => '']);

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

    private function store(string $variantId, int $gross, string $taxClass, int $basisPoints, int $version): void
    {
        $this->prices->store(VariantPrice::reconstitute(
            VariantId::fromString($variantId),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString($taxClass),
            $version,
            $this->updatedAt,
        ));
    }
}
