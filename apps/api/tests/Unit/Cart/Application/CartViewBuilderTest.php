<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Application;

use App\Cart\Application\Port\CartPrice;
use App\Cart\Application\Port\ProductAvailability;
use App\Cart\Application\Port\ProductImage;
use App\Cart\Application\Port\ProductSummary;
use App\Cart\Application\ProductData;
use App\Cart\Application\View\CartLineState;
use App\Cart\Application\View\CartLineView;
use App\Cart\Application\View\CartViewBuilder;
use App\Cart\Application\View\GrossAmount;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\Quantity;
use PHPUnit\Framework\TestCase;

final class CartViewBuilderTest extends TestCase
{
    private const string CART = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-00000000000a';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-00000000000b';
    private const string C = '0192f0c4-7c1a-7c4e-9a6e-00000000000c';
    private const string D = '0192f0c4-7c1a-7c4e-9a6e-00000000000d';
    private const string E = '0192f0c4-7c1a-7c4e-9a6e-00000000000e';

    public function testEmptyCartHasZeroTotalsInPln(): void
    {
        $view = new CartViewBuilder()->build(CartId::fromString(self::CART), [], new ProductData([], [], []));

        self::assertSame(self::CART, $view->id);
        self::assertSame([], $view->lines);
        self::assertSame(0, $view->totals->gross);
        self::assertSame('PLN', $view->totals->currency);
        self::assertSame(0, $view->totals->itemCount);
        self::assertFalse($view->hasExcludedLines);
    }

    public function testEmptyViewWithoutACart(): void
    {
        $view = new CartViewBuilder()->empty(null);

        self::assertNull($view->id);
        self::assertSame([], $view->lines);
        self::assertSame(0, $view->totals->itemCount);
    }

    public function testPurchasableLinesHaveTheirTotalsAndMakeTheSum(): void
    {
        $image = new ProductImage('Biegaczka', 'listing', '1:1', ['webp' => [['width' => 256, 'url' => 'https://img.test/a']]]);
        $data = new ProductData(
            [new ProductSummary(self::id(self::A), 'Buty', $image), new ProductSummary(self::id(self::B), 'Czapka', null)],
            [self::price(self::A, 19_999), self::price(self::B, 4_990)],
            [new ProductAvailability(self::id(self::A), 5), new ProductAvailability(self::id(self::B), 3)],
        );

        $view = new CartViewBuilder()->build(null, [self::line(self::A, 2), self::line(self::B, 3)], $data);

        self::assertNull($view->id);
        self::assertSame([self::A, self::B], array_map(static fn (CartLineView $l): string => $l->productId, $view->lines));
        $a = $view->lines[0];
        self::assertSame('Buty', $a->product?->name);
        self::assertSame($image, $a->product->image);
        self::assertSame(2, $a->quantity);
        self::assertEquals(new GrossAmount(19_999, 'PLN'), $a->unitPrice);
        self::assertEquals(new GrossAmount(39_998, 'PLN'), $a->lineTotal);
        self::assertSame('purchasable', $a->state);
        self::assertSame(14_970, $view->lines[1]->lineTotal?->gross, 'quantity equal to the available units is purchasable');
        self::assertSame(54_968, $view->totals->gross);
        self::assertSame(5, $view->totals->itemCount);
        self::assertFalse($view->hasExcludedLines);
    }

    public function testEveryStateInTheOrderOfPriorityAndOnlyPurchasableLinesInTheSum(): void
    {
        $data = new ProductData(
            // A is unknown to Catalog but has a price and stock; B has no price and no stock.
            [new ProductSummary(self::id(self::B), 'Bez ceny', null), new ProductSummary(self::id(self::C), 'Brak', null), new ProductSummary(self::id(self::D), 'Mało', null), new ProductSummary(self::id(self::E), 'Jest', null)],
            [self::price(self::A, 100), self::price(self::C, 300), self::price(self::D, 400), self::price(self::E, 500)],
            [new ProductAvailability(self::id(self::A), 10), new ProductAvailability(self::id(self::C), 0), new ProductAvailability(self::id(self::D), 2), new ProductAvailability(self::id(self::E), 9)],
        );

        $view = new CartViewBuilder()->build(null, [self::line(self::A, 1), self::line(self::B, 1), self::line(self::C, 1), self::line(self::D, 3), self::line(self::E, 2)], $data);

        self::assertSame(
            [CartLineState::UnavailableProduct->value, CartLineState::NoPrice->value, CartLineState::OutOfStock->value, CartLineState::InsufficientStock->value, CartLineState::Purchasable->value],
            array_map(static fn (CartLineView $l): string => $l->state, $view->lines),
        );
        self::assertNull($view->lines[0]->product, 'a product unknown to Catalog has neither name nor image');
        self::assertSame(100, $view->lines[0]->unitPrice?->gross, 'the price is shown whenever Pricing has one');
        self::assertNull($view->lines[1]->unitPrice);
        self::assertSame(400, $view->lines[3]->unitPrice?->gross);
        self::assertSame([null, null, null, null, 1000], array_map(static fn (CartLineView $l): ?int => $l->lineTotal?->gross, $view->lines));
        self::assertSame(1000, $view->totals->gross);
        self::assertSame(8, $view->totals->itemCount, 'the count covers every line');
        self::assertTrue($view->hasExcludedLines);
    }

    public function testAProductWithoutAStockLevelIsOutOfStock(): void
    {
        $data = new ProductData([new ProductSummary(self::id(self::A), 'Buty', null)], [self::price(self::A, 100)], []);

        $view = new CartViewBuilder()->build(null, [self::line(self::A, 1)], $data);

        self::assertSame('out_of_stock', $view->lines[0]->state);
    }

    public function testTheViewNeverCarriesTheAvailableUnits(): void
    {
        $data = new ProductData([new ProductSummary(self::id(self::A), 'Buty', null)], [self::price(self::A, 100)], [new ProductAvailability(self::id(self::A), 7357)]);

        $view = new CartViewBuilder()->build(null, [self::line(self::A, 1)], $data);

        self::assertStringNotContainsString('7357', json_encode($view, \JSON_THROW_ON_ERROR));
    }

    private static function id(string $id): ProductId
    {
        return ProductId::fromString($id);
    }

    private static function line(string $id, int $quantity): CartLine
    {
        return new CartLine(self::id($id), Quantity::of($quantity), new \DateTimeImmutable('2026-11-03 12:00:00+00:00'));
    }

    private static function price(string $id, int $gross): CartPrice
    {
        return new CartPrice(self::id($id), Money::of($gross, Currency::PLN));
    }
}
