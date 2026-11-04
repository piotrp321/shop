<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Application;

use App\Cart\Application\Query\GetProductOffers;
use App\Cart\Application\Query\GetProductOffersHandler;
use App\Cart\Application\Query\InvalidProductOffersQuery;
use App\Cart\Application\View\ProductOfferView;
use App\Cart\Application\View\VariantOfferView;
use App\Tests\Unit\Cart\InMemoryProducts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The offers of the products of a page of the list: the variants from Catalog, their prices from Pricing and their
 * stock from Inventory, one call of each port for all the products; the "from" price and the level of a product come
 * from its purchasable variants only.
 */
final class GetProductOffersHandlerTest extends TestCase
{
    private const string SHOES = 'c0ffee00-0000-4000-8000-000000000001';
    private const string SHOES_42 = '0192f0c4-7c1a-7c4e-9a6e-000000000042';
    private const string SHOES_43 = '0192f0c4-7c1a-7c4e-9a6e-000000000043';
    private const string SHOES_44 = '0192f0c4-7c1a-7c4e-9a6e-000000000044';
    private const string SHOES_45 = '0192f0c4-7c1a-7c4e-9a6e-000000000045';
    private const string DUMBBELLS = 'c0ffee00-0000-4000-8000-000000000002';
    private const string DUMBBELLS_DEFAULT = '0192f0c4-7c1a-7c4e-9a6e-0000000000d0';
    private const string KAYAK = 'c0ffee00-0000-4000-8000-000000000003';
    private const string KAYAK_GREEN = '0192f0c4-7c1a-7c4e-9a6e-0000000000a1';
    private const string KAYAK_RED = '0192f0c4-7c1a-7c4e-9a6e-0000000000a2';
    private const string UNKNOWN = 'c0ffee00-0000-4000-8000-0000000000ff';

    private InMemoryProducts $products;

    protected function setUp(): void
    {
        $this->products = new InMemoryProducts()
            ->axes(self::SHOES, ['Kolor', 'Rozmiar'])
            ->variant(self::SHOES_42, 'Buty Asfalt', 30_000, 20, self::SHOES, 'czarne, 42')
            // No current price (none, or at an outdated VAT rate: Pricing gives none for it).
            ->variant(self::SHOES_43, 'Buty Asfalt', null, 20, self::SHOES, 'czarne, 43')
            ->variant(self::SHOES_44, 'Buty Asfalt', 25_000, 0, self::SHOES, 'czarne, 44')
            ->variant(self::SHOES_45, 'Buty Asfalt', 32_000, 3, self::SHOES, 'czarne, 45')
            ->variant(self::DUMBBELLS_DEFAULT, 'Hantle', 19_900, 4, self::DUMBBELLS)
            ->axes(self::KAYAK, ['Kolor'])
            ->variant(self::KAYAK_GREEN, 'Kajak', 150_000, 0, self::KAYAK, 'zielony')
            ->variant(self::KAYAK_RED, 'Kajak', null, 10, self::KAYAK, 'czerwony');
    }

    public function testTheOfferOfAProductComesFromItsPurchasableVariants(): void
    {
        [$shoes] = $this->offers([self::SHOES]);

        self::assertSame(self::SHOES, $shoes->productId);
        self::assertSame(['Kolor', 'Rozmiar'], $shoes->variantAxes);
        self::assertSame(30_000, $shoes->fromGross, 'the cheaper 44 has no unit, so it is left out');
        self::assertTrue($shoes->hasPriceRange);
        self::assertSame('PLN', $shoes->currency);
        self::assertSame('in_stock', $shoes->level);
        self::assertSame(
            [
                [self::SHOES_42, 'czarne, 42', 30_000, 'in_stock', true],
                [self::SHOES_43, 'czarne, 43', null, 'in_stock', false],
                [self::SHOES_44, 'czarne, 44', 25_000, 'out_of_stock', false],
                [self::SHOES_45, 'czarne, 45', 32_000, 'low_stock', true],
            ],
            array_map(static fn (VariantOfferView $v): array => [$v->variantId, $v->label, $v->gross, $v->level, $v->purchasable], $shoes->variants),
        );
    }

    public function testAProductWithTheDefaultVariantHasItsPriceWithoutARange(): void
    {
        [$dumbbells] = $this->offers([self::DUMBBELLS]);

        self::assertSame([], $dumbbells->variantAxes);
        self::assertSame(19_900, $dumbbells->fromGross);
        self::assertFalse($dumbbells->hasPriceRange);
        self::assertSame('low_stock', $dumbbells->level);
        self::assertSame([[self::DUMBBELLS_DEFAULT, '', 19_900, 'low_stock', true]], array_map(static fn (VariantOfferView $v): array => [$v->variantId, $v->label, $v->gross, $v->level, $v->purchasable], $dumbbells->variants));
    }

    public function testAProductWithoutAPurchasableVariantHasNoPriceAndIsOutOfStock(): void
    {
        [$kayak] = $this->offers([self::KAYAK]);

        self::assertNull($kayak->fromGross);
        self::assertFalse($kayak->hasPriceRange);
        self::assertSame('out_of_stock', $kayak->level);
        self::assertSame([[self::KAYAK_GREEN, 150_000, false], [self::KAYAK_RED, null, false]], array_map(static fn (VariantOfferView $v): array => [$v->variantId, $v->gross, $v->purchasable], $kayak->variants));
    }

    public function testEveryRequestedProductHasOneOfferInTheOrderOfTheRequest(): void
    {
        $offers = $this->offers([self::KAYAK, self::UNKNOWN, strtoupper(self::SHOES), self::KAYAK, self::DUMBBELLS]);

        self::assertSame([self::KAYAK, self::UNKNOWN, self::SHOES, self::DUMBBELLS], array_map(static fn (ProductOfferView $o): string => $o->productId, $offers));
        $unknown = $offers[1];
        self::assertSame([], $unknown->variantAxes, 'a product unknown to Catalog has no variants');
        self::assertSame([], $unknown->variants);
        self::assertNull($unknown->fromGross);
        self::assertFalse($unknown->hasPriceRange);
        self::assertSame('PLN', $unknown->currency);
        self::assertSame('out_of_stock', $unknown->level);
    }

    public function testEveryPortIsAskedOnceForAllTheVariantsOfAllTheProducts(): void
    {
        $this->offers([self::SHOES, self::DUMBBELLS, self::UNKNOWN, self::KAYAK]);

        self::assertSame([[self::SHOES, self::DUMBBELLS, self::UNKNOWN, self::KAYAK]], $this->products->variantsOfCalls());
        $variants = [self::SHOES_42, self::SHOES_43, self::SHOES_44, self::SHOES_45, self::DUMBBELLS_DEFAULT, self::KAYAK_GREEN, self::KAYAK_RED];
        self::assertSame(['summaries' => [], 'current' => [$variants], 'of' => [$variants]], $this->products->calls());
    }

    public function testWithoutVariantsNeitherPricesNorStockAreAsked(): void
    {
        $offers = $this->offers([self::UNKNOWN]);

        self::assertCount(1, $offers);
        self::assertSame(['summaries' => [], 'current' => [], 'of' => []], $this->products->calls());
    }

    public function testTheOffersNeverCarryTheAvailableUnits(): void
    {
        $this->products->variant(self::DUMBBELLS_DEFAULT, 'Hantle', 19_900, 7357, self::DUMBBELLS);

        self::assertStringNotContainsString('7357', json_encode($this->offers([self::DUMBBELLS]), \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'no id' => [[]];
        yield '49 ids' => [array_map(static fn (int $n): string => \sprintf('c0ffee00-0000-4000-8000-%012d', $n), range(1, 49))];
        yield 'not a uuid' => [[self::SHOES, 'abc']];
    }

    /**
     * @param list<string> $productIds
     */
    #[DataProvider('invalidQueries')]
    public function testAnInvalidQueryAsksNoPort(array $productIds): void
    {
        try {
            $this->offers($productIds);
            self::fail('Expected InvalidProductOffersQuery.');
        } catch (InvalidProductOffersQuery) {
        }
        self::assertSame([], $this->products->variantsOfCalls());
    }

    public function test48IdsAreAllowed(): void
    {
        self::assertCount(48, $this->offers(array_map(static fn (int $n): string => \sprintf('c0ffee00-0000-4000-8000-%012d', $n), range(1, 48))));
    }

    /**
     * @param list<string> $productIds
     *
     * @return list<ProductOfferView>
     */
    private function offers(array $productIds): array
    {
        return new GetProductOffersHandler($this->products, $this->products, $this->products)(new GetProductOffers($productIds));
    }
}
