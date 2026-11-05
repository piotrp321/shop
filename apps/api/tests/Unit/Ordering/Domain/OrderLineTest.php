<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Currency;
use App\Ordering\Domain\InvalidOrder;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\OrderTotals;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\ProductId;
use App\Ordering\Domain\VariantId;
use PHPUnit\Framework\TestCase;

/**
 * OrderLine and OrderTotals: the values of the lines and the sums of an order, computed only here.
 */
final class OrderLineTest extends TestCase
{
    private const string PRODUCT = 'c0ffee00-7c1a-7c4e-9a6e-000000000042';

    public function testLineValuesAreTheUnitPriceTimesTheQuantity(): void
    {
        $line = self::line(1, 3, PriceCopy::of(10_000, 2_300, 12_300, 2300, 'standard', Currency::PLN));

        self::assertSame(30_000, $line->lineNet()->amount());
        self::assertSame(6_900, $line->lineVat()->amount());
        self::assertSame(36_900, $line->lineGross()->amount());
        self::assertSame(3, $line->quantity());
        self::assertSame('Buty Asfalt', $line->productName());
        self::assertSame('czarne, 42', $line->variantLabel());
        self::assertSame(self::PRODUCT, $line->productId()->toString());
        self::assertSame(12_300, $line->unitPrice()->gross()->amount());
    }

    public function testQuantityIsOneToNinetyNine(): void
    {
        $price = PriceCopy::of(100, 23, 123, 2300, 'standard', Currency::PLN);
        self::assertSame(1, self::line(1, 1, $price)->quantity());
        self::assertSame(99, self::line(1, 99, $price)->quantity());

        foreach ([0, 100, -1] as $quantity) {
            try {
                self::line(1, $quantity, $price);
                self::fail('Expected InvalidOrder for '.$quantity);
            } catch (InvalidOrder) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testProductNameIsRequiredAndNamesAreAtMost255Characters(): void
    {
        $price = PriceCopy::of(100, 23, 123, 2300, 'standard', Currency::PLN);
        $defaultVariant = OrderLine::of(self::variant(1), ProductId::fromString(self::PRODUCT), str_repeat('n', 255), '', 1, $price);
        self::assertSame('', $defaultVariant->variantLabel());

        foreach ([['', ''], [str_repeat('n', 256), ''], ['Buty', str_repeat('l', 256)]] as [$name, $label]) {
            try {
                OrderLine::of(self::variant(1), ProductId::fromString(self::PRODUCT), $name, $label, 1, $price);
                self::fail('Expected InvalidOrder.');
            } catch (InvalidOrder) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTotalsAreTheSumsOfTheLinesAndNetPlusVatIsGross(): void
    {
        $totals = OrderTotals::of([
            self::line(1, 2, PriceCopy::of(10_000, 2_300, 12_300, 2300, 'standard', Currency::PLN)),
            self::line(2, 3, PriceCopy::of(1_852, 148, 2_000, 800, 'reduced', Currency::PLN)),
            self::line(3, 1, PriceCopy::of(1, 0, 1, 0, 'zero', Currency::PLN)),
        ]);

        self::assertSame(20_000 + 5_556 + 1, $totals->net()->amount());
        self::assertSame(4_600 + 444, $totals->vat()->amount());
        self::assertSame(24_600 + 6_000 + 1, $totals->gross()->amount());
        self::assertSame($totals->gross()->amount(), $totals->net()->amount() + $totals->vat()->amount());
    }

    public function testTheLargestOrderFitsInAnInteger(): void
    {
        // 50 lines of 99 units at 99 999 999 grosze.
        $price = PriceCopy::of(81_300_813, 18_699_186, 99_999_999, 2300, 'standard', Currency::PLN);
        $lines = [];
        for ($i = 1; $i <= 50; ++$i) {
            $lines[] = self::line($i, 99, $price);
        }

        $totals = OrderTotals::of($lines);

        self::assertSame(50 * 99 * 99_999_999, $totals->gross()->amount());
        self::assertSame(494_999_995_050, $totals->gross()->amount());
        self::assertSame($totals->gross()->amount(), $totals->net()->amount() + $totals->vat()->amount());
    }

    public function testTotalsOfNoLineAreRefused(): void
    {
        $this->expectException(InvalidOrder::class);

        OrderTotals::of([]);
    }

    private static function line(int $variant, int $quantity, PriceCopy $price): OrderLine
    {
        return OrderLine::of(self::variant($variant), ProductId::fromString(self::PRODUCT), 'Buty Asfalt', 'czarne, 42', $quantity, $price);
    }

    private static function variant(int $number): VariantId
    {
        return VariantId::fromString(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $number));
    }
}
