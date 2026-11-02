<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Price\GrossPrice;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testMoneyIsAnIntegerAmountInMinorUnitsWithACurrency(): void
    {
        $money = Money::of(5490, Currency::PLN);

        self::assertSame(5490, $money->amount());
        self::assertTrue($money->equals(Money::of(5490, Currency::PLN)));
        self::assertFalse($money->equals(Money::of(5491, Currency::PLN)));
    }

    #[DataProvider('validGross')]
    public function testGrossPriceAcceptsOneGroszToMaximum(int $amount): void
    {
        $gross = GrossPrice::pln($amount);

        self::assertSame($amount, $gross->amount());
        self::assertTrue($gross->money()->equals(Money::of($amount, Currency::PLN)));
    }

    /** @return iterable<string, array{int}> */
    public static function validGross(): iterable
    {
        yield '0,01 zł' => [1];
        yield '54,90 zł' => [5490];
        yield '999 999,99 zł' => [99_999_999];
    }

    #[DataProvider('grossOutOfRange')]
    public function testGrossPriceOutOfRangeIsInvalidInput(int $amount): void
    {
        $this->expectException(InvalidInput::class);
        // A fixed message: the rejected amount (user input) is never echoed.
        $this->expectExceptionMessage('The gross price must be between 0.01 and 999 999.99 PLN.');

        GrossPrice::pln($amount);
    }

    /** @return iterable<string, array{int}> */
    public static function grossOutOfRange(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'one grosz above the maximum' => [100_000_000];
        yield 'int max' => [\PHP_INT_MAX];
        yield 'int min' => [\PHP_INT_MIN];
    }

    public function testGrossPriceComparesByValue(): void
    {
        self::assertTrue(GrossPrice::pln(100)->equals(GrossPrice::pln(100)));
        self::assertFalse(GrossPrice::pln(100)->equals(GrossPrice::pln(101)));
    }
}
