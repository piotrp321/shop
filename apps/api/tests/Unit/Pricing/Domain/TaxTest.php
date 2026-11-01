<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function demoRates(): iterable
    {
        yield 'standard' => ['standard', 2300];
        yield 'printed_book' => ['printed_book', 500];
        yield 'printed_map' => ['printed_map', 500];
        yield 'freeze_dried_meal' => ['freeze_dried_meal', 500];
        yield 'energy_bar' => ['energy_bar', 500];
        yield 'energy_gel' => ['energy_gel', 800];
        yield 'protein_powder' => ['protein_powder', 800];
        yield 'electrolyte_tablet' => ['electrolyte_tablet', 2300];
    }

    #[DataProvider('demoRates')]
    public function testPolishRatesFollowTheSpecification(string $class, int $basisPoints): void
    {
        $rate = new PolishVatRates()->rateFor(TaxClass::fromString($class));

        self::assertSame($basisPoints, $rate->basisPoints());
    }

    public function testPolishRatesListEveryClassWithItsRate(): void
    {
        $expected = [];
        foreach (self::demoRates() as [$class, $basisPoints]) {
            $expected[$class] = $basisPoints;
        }

        $actual = array_map(static fn (VatRate $rate): int => $rate->basisPoints(), new PolishVatRates()->all());

        self::assertSame($expected, $actual);
    }

    public function testClassWithoutRateIsInvalidInput(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('The tax class of the product has no VAT rate.');

        new PolishVatRates()->rateFor(TaxClass::fromString('luxury'));
    }

    public function testVatRateKeepsBasisPoints(): void
    {
        self::assertSame(0, VatRate::fromBasisPoints(0)->basisPoints());
        self::assertSame(10000, VatRate::fromBasisPoints(10000)->basisPoints());
        self::assertTrue(VatRate::fromBasisPoints(2300)->equals(VatRate::fromBasisPoints(2300)));
        self::assertFalse(VatRate::fromBasisPoints(2300)->equals(VatRate::fromBasisPoints(800)));
    }

    #[DataProvider('invalidBasisPoints')]
    public function testVatRateOutsideZeroToHundredPercentIsRejected(int $basisPoints): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VatRate::fromBasisPoints($basisPoints);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidBasisPoints(): iterable
    {
        yield 'negative' => [-1];
        yield 'above 100%' => [10001];
        yield 'int max' => [\PHP_INT_MAX];
    }

    public function testTaxClassIsATextCode(): void
    {
        $class = TaxClass::fromString('printed_book');

        self::assertSame('printed_book', $class->toString());
        self::assertTrue($class->equals(TaxClass::fromString('printed_book')));
        self::assertFalse($class->equals(TaxClass::fromString('printed_map')));
    }

    #[DataProvider('invalidClassCodes')]
    public function testTaxClassRejectsMalformedCodes(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TaxClass::fromString($code);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidClassCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Standard'];
        yield 'space' => ['printed book'];
        yield 'leading digit' => ['5percent'];
        yield 'longer than the column (32)' => [str_repeat('a', 33)];
    }
}
