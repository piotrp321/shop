<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Application;

use App\Cart\Application\Port\CartPrice;
use App\Cart\Application\Port\ProductImage;
use App\Cart\Application\Port\VariantAvailability;
use App\Cart\Application\Port\VariantSummary;
use App\Cart\Application\ProductData;
use App\Cart\Application\View\AppliedCartDiscount;
use App\Cart\Application\View\CartLineState;
use App\Cart\Application\View\CartLineView;
use App\Cart\Application\View\CartViewBuilder;
use App\Cart\Application\View\GrossAmount;
use App\Cart\Application\View\RejectedCartDiscount;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\Quantity;
use App\Cart\Domain\StockLevel;
use App\Cart\Domain\VariantId;
use App\Tests\Unit\Cart\FakeCartQuote;
use PHPUnit\Framework\TestCase;

final class CartViewBuilderTest extends TestCase
{
    private const string CART = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-00000000000a';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-00000000000b';
    private const string C = '0192f0c4-7c1a-7c4e-9a6e-00000000000c';
    private const string D = '0192f0c4-7c1a-7c4e-9a6e-00000000000d';
    private const string E = '0192f0c4-7c1a-7c4e-9a6e-00000000000e';

    private FakeCartQuote $quote;

    protected function setUp(): void
    {
        $this->quote = new FakeCartQuote();
    }

    public function testEmptyCartHasZeroTotalsInPlnWithoutAQuote(): void
    {
        $view = $this->builder()->build(CartId::fromString(self::CART), [], new ProductData([], [], []), 'WITAJ10');

        self::assertSame(self::CART, $view->id);
        self::assertSame([], $view->lines);
        self::assertSame([0, 0, 0], [$view->totals->subtotalGross, $view->totals->discountGross, $view->totals->gross]);
        self::assertSame('PLN', $view->totals->currency);
        self::assertSame(0, $view->totals->itemCount);
        self::assertFalse($view->hasExcludedLines);
        self::assertNull($view->discount);
        self::assertSame([], $this->quote->calls);
    }

    public function testEmptyViewWithoutACart(): void
    {
        $view = $this->builder()->empty(null);

        self::assertNull($view->id);
        self::assertSame([], $view->lines);
        self::assertSame(0, $view->totals->itemCount);
    }

    public function testPurchasableLinesHaveTheirTotalsAndMakeTheSum(): void
    {
        $image = new ProductImage('Biegaczka', 'listing', '1:1', ['webp' => [['width' => 256, 'url' => 'https://img.test/a']]]);
        $data = new ProductData(
            [self::summary(self::A, 'Buty', $image), self::summary(self::B, 'Czapka', null)],
            [self::price(self::A, 19_999), self::price(self::B, 4_990)],
            [self::stock(self::A, 5), self::stock(self::B, 3)],
        );
        $this->quote->price(self::A, 19_999)->price(self::B, 4_990);

        $view = $this->builder()->build(null, [self::line(self::A, 2), self::line(self::B, 3)], $data);

        self::assertNull($view->id);
        self::assertSame([self::A, self::B], array_map(static fn (CartLineView $l): string => $l->variantId, $view->lines));
        $a = $view->lines[0];
        self::assertSame('Buty', $a->product?->name);
        self::assertSame(self::productOf(self::A), $a->product->productId);
        self::assertSame('Buty wariant', $a->product->variantLabel);
        self::assertSame($image, $a->product->image);
        self::assertSame(2, $a->quantity);
        self::assertEquals(new GrossAmount(19_999, 'PLN'), $a->unitPrice);
        self::assertEquals(new GrossAmount(39_998, 'PLN'), $a->lineTotal);
        self::assertSame('purchasable', $a->state);
        self::assertSame(14_970, $view->lines[1]->lineTotal?->gross, 'quantity equal to the available units is purchasable');
        self::assertSame([54_968, 0, 54_968], [$view->totals->subtotalGross, $view->totals->discountGross, $view->totals->gross]);
        self::assertSame(5, $view->totals->itemCount);
        self::assertFalse($view->hasExcludedLines);
        self::assertNull($view->discount);
        self::assertSame([[[[self::A, 2], [self::B, 3]], null]], $this->quote->calls);
    }

    public function testThePricesOfPurchasableLinesAndTheTotalsComeFromTheQuoteWithTheCode(): void
    {
        $data = new ProductData(
            [self::summary(self::A, 'Buty', null), self::summary(self::B, 'Czapka', null), self::summary(self::C, 'Brak', null)],
            [self::price(self::A, 19_999), self::price(self::B, 4_990), self::price(self::C, 700)],
            [self::stock(self::A, 5), self::stock(self::B, 3), self::stock(self::C, 0)],
        );
        // The quote is read at another moment than CartPrices (a promotion may start in between).
        $this->quote->price(self::A, 15_999)->price(self::B, 4_990)
            ->discount(new AppliedCartDiscount('WITAJ10', 4_696, 'percent', 10), 4_696);

        $view = $this->builder()->build(null, [self::line(self::A, 2), self::line(self::C, 1), self::line(self::B, 3)], $data, 'witaj10');

        self::assertSame([[[[self::A, 2], [self::B, 3]], 'witaj10']], $this->quote->calls, 'only the purchasable lines, in the order of the cart');
        self::assertEquals(new GrossAmount(15_999, 'PLN'), $view->lines[0]->unitPrice);
        self::assertEquals(new GrossAmount(31_998, 'PLN'), $view->lines[0]->lineTotal, 'the value before the discount');
        self::assertEquals(new GrossAmount(700, 'PLN'), $view->lines[1]->unitPrice, 'an excluded line keeps the price of CartPrices');
        self::assertNull($view->lines[1]->lineTotal);
        self::assertSame(14_970, $view->lines[2]->lineTotal?->gross);
        self::assertSame([46_968, 4_696, 42_272], [$view->totals->subtotalGross, $view->totals->discountGross, $view->totals->gross]);
        self::assertSame(6, $view->totals->itemCount);
        self::assertTrue($view->hasExcludedLines);
        self::assertEquals(new AppliedCartDiscount('WITAJ10', 4_696, 'percent', 10), $view->discount);
    }

    public function testARejectedCodeComesWithTheTotalsWithoutDiscount(): void
    {
        $data = new ProductData([self::summary(self::A, 'Buty', null)], [self::price(self::A, 10_000)], [self::stock(self::A, 5)]);
        $rejected = new RejectedCartDiscount('MINUS50', 'below_minimum', 'Ten kod działa przy zamówieniu od 300,00 zł.', 30_000);
        $this->quote->price(self::A, 10_000)->discount($rejected);

        $view = $this->builder()->build(null, [self::line(self::A, 1)], $data, 'minus50');

        self::assertSame([10_000, 0, 10_000], [$view->totals->subtotalGross, $view->totals->discountGross, $view->totals->gross]);
        self::assertSame($rejected, $view->discount);
    }

    public function testWithoutPurchasableLinesThereIsNoQuoteZeroTotalsAndNoDiscountEvenWithACode(): void
    {
        $data = new ProductData(
            [self::summary(self::A, 'Brak', null), self::summary(self::B, 'Mało', null)],
            [self::price(self::A, 300), self::price(self::B, 400)],
            [self::stock(self::A, 0), self::stock(self::B, 1)],
        );
        $this->quote->price(self::A, 300)->price(self::B, 400)->discount(new AppliedCartDiscount('WITAJ10', 1, 'percent', 10), 1);

        $view = $this->builder()->build(null, [self::line(self::A, 1), self::line(self::B, 2)], $data, 'WITAJ10');

        self::assertSame([], $this->quote->calls);
        self::assertSame([300, 400], array_map(static fn (CartLineView $l): ?int => $l->unitPrice?->gross, $view->lines));
        self::assertSame([null, null], array_map(static fn (CartLineView $l): ?int => $l->lineTotal?->gross, $view->lines));
        self::assertSame([0, 0, 0], [$view->totals->subtotalGross, $view->totals->discountGross, $view->totals->gross]);
        self::assertSame(3, $view->totals->itemCount);
        self::assertNull($view->discount);
        self::assertTrue($view->hasExcludedLines);
    }

    public function testAPurchasableLineTheQuoteLeavesOutHasNoPrice(): void
    {
        // The price was removed between the read of CartPrices and the quote.
        $data = new ProductData(
            [self::summary(self::A, 'Buty', null), self::summary(self::B, 'Czapka', null)],
            [self::price(self::A, 1_000), self::price(self::B, 2_000)],
            [self::stock(self::A, 5), self::stock(self::B, 5)],
        );
        $this->quote->price(self::B, 2_000);

        $view = $this->builder()->build(null, [self::line(self::A, 1), self::line(self::B, 1)], $data);

        self::assertSame(['no_price', 'purchasable'], array_map(static fn (CartLineView $l): string => $l->state, $view->lines));
        self::assertNull($view->lines[0]->unitPrice);
        self::assertNull($view->lines[0]->lineTotal);
        self::assertSame([2_000, 0, 2_000], [$view->totals->subtotalGross, $view->totals->discountGross, $view->totals->gross]);
        self::assertTrue($view->hasExcludedLines);
    }

    public function testEveryStateInTheOrderOfPriorityAndOnlyPurchasableLinesInTheSum(): void
    {
        $data = new ProductData(
            // A is unknown to Catalog but has a price and stock; B has no price and no stock.
            [self::summary(self::B, 'Bez ceny', null), self::summary(self::C, 'Brak', null), self::summary(self::D, 'Mało', null), self::summary(self::E, 'Jest', null)],
            [self::price(self::A, 100), self::price(self::C, 300), self::price(self::D, 400), self::price(self::E, 500)],
            [self::stock(self::A, 10), self::stock(self::C, 0), self::stock(self::D, 2), self::stock(self::E, 9)],
        );
        $this->quote->price(self::E, 500);

        $view = $this->builder()->build(null, [self::line(self::A, 1), self::line(self::B, 1), self::line(self::C, 1), self::line(self::D, 3), self::line(self::E, 2)], $data);

        self::assertSame(
            [CartLineState::UnavailableProduct->value, CartLineState::NoPrice->value, CartLineState::OutOfStock->value, CartLineState::InsufficientStock->value, CartLineState::Purchasable->value],
            array_map(static fn (CartLineView $l): string => $l->state, $view->lines),
        );
        self::assertNull($view->lines[0]->product, 'a product unknown to Catalog has neither name nor image');
        self::assertSame(100, $view->lines[0]->unitPrice?->gross, 'the price is shown whenever Pricing has one');
        self::assertNull($view->lines[1]->unitPrice);
        self::assertSame(400, $view->lines[3]->unitPrice?->gross);
        self::assertSame([null, null, null, null, 1000], array_map(static fn (CartLineView $l): ?int => $l->lineTotal?->gross, $view->lines));
        self::assertSame([1000, 1000], [$view->totals->subtotalGross, $view->totals->gross]);
        self::assertSame(8, $view->totals->itemCount, 'the count covers every line');
        self::assertTrue($view->hasExcludedLines);
    }

    public function testAProductWithoutAStockLevelIsOutOfStock(): void
    {
        $data = new ProductData([self::summary(self::A, 'Buty', null)], [self::price(self::A, 100)], []);

        $view = $this->builder()->build(null, [self::line(self::A, 1)], $data);

        self::assertSame('out_of_stock', $view->lines[0]->state);
    }

    public function testTheViewNeverCarriesTheAvailableUnits(): void
    {
        $data = new ProductData([self::summary(self::A, 'Buty', null)], [self::price(self::A, 100)], [self::stock(self::A, 7357)]);
        $this->quote->price(self::A, 100);

        $view = $this->builder()->build(null, [self::line(self::A, 1)], $data);

        self::assertStringNotContainsString('7357', json_encode($view, \JSON_THROW_ON_ERROR));
    }

    private function builder(): CartViewBuilder
    {
        return new CartViewBuilder($this->quote);
    }

    private static function id(string $id): VariantId
    {
        return VariantId::fromString($id);
    }

    /**
     * A variant of its own product (the product id differs from the variant id), labelled after the product name.
     */
    private static function summary(string $id, string $name, ?ProductImage $image): VariantSummary
    {
        return new VariantSummary(self::id($id), ProductId::fromString(self::productOf($id)), $name, $name.' wariant', $image);
    }

    private static function productOf(string $variantId): string
    {
        return 'bbbbbbbb'.substr($variantId, 8);
    }

    private static function stock(string $id, int $available): VariantAvailability
    {
        return new VariantAvailability(self::id($id), $available, 0 === $available ? StockLevel::OutOfStock : StockLevel::InStock);
    }

    private static function line(string $id, int $quantity): CartLine
    {
        return new CartLine(self::id($id), Quantity::of($quantity), new \DateTimeImmutable('2026-11-03 12:00:00+00:00'));
    }

    private static function price(string $id, int $gross): CartPrice
    {
        return new CartPrice(self::id($id), Money::of($gross, Currency::PLN), Money::of($gross, Currency::PLN), null);
    }
}
