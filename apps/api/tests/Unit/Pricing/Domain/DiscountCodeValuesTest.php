<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\InvalidDiscountCode;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;
use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Promotion\PercentOff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The value objects of a discount code (decisions D1, Q4, Q7).
 */
final class DiscountCodeValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validCodes(): iterable
    {
        yield 'upper case' => ['WITAJ10', 'WITAJ10'];
        yield 'lower case is the same code' => ['witaj10', 'WITAJ10'];
        yield 'mixed case' => ['Lato-2026', 'LATO-2026'];
        yield 'trimmed' => ['  minus50 ', 'MINUS50'];
        yield 'trimmed of Unicode spaces' => ["\u{A0}\u{3000}abc\u{2003}", 'ABC'];
        yield 'trimmed of a zero width space' => ["\u{200B}abc\u{FEFF}", 'ABC'];
        yield 'three characters' => ['a-1', 'A-1'];
        yield 'thirty two characters' => [str_repeat('A', 32), str_repeat('A', 32)];
        yield 'only digits' => ['123', '123'];
        yield 'only dashes' => ['---', '---'];
    }

    #[DataProvider('validCodes')]
    public function testACodeIsNormalisedToUpperCase(string $text, string $expected): void
    {
        self::assertSame($expected, DiscountCodeValue::of($text)->toString());
    }

    public function testCodesThatDifferOnlyInCaseAreEqual(): void
    {
        self::assertTrue(DiscountCodeValue::of('witaj10')->equals(DiscountCodeValue::of('WITAJ10')));
        self::assertFalse(DiscountCodeValue::of('witaj10')->equals(DiscountCodeValue::of('WITAJ11')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no-break spaces only' => ["\u{A0}\u{A0}\u{A0}"];
        yield 'two characters' => ['AB'];
        yield 'two characters padded to three' => [' AB '];
        yield 'thirty three characters' => [str_repeat('A', 33)];
        yield 'a space inside' => ['WIT AJ'];
        yield 'a Polish letter' => ['ZAŻÓŁĆ'];
        yield 'an underscore' => ['WITAJ_10'];
        yield 'a percent sign' => ['10%OFF'];
        yield 'a NUL byte' => ["WIT\0AJ"];
        yield 'a trailing new line' => ["WITAJ\n1"];
        yield 'a line separator' => ["WIT\u{2028}AJ"];
        yield 'a no-break space inside' => ["WIT\u{A0}AJ"];
        yield 'invalid UTF-8' => ["WIT\xFFAJ"];
        yield 'a fullwidth letter' => ['ＷＩＴＡＪ'];
    }

    #[DataProvider('invalidCodes')]
    public function testACodeOutsideTheAlphabetOrTheLengthIsRefused(string $text): void
    {
        $refused = self::refusal(static fn () => DiscountCodeValue::of($text));

        self::assertSame(InvalidDiscountCode::CODE, $refused->field);
        self::assertSame('Kod ma 3–32 znaki: litery, cyfry i myślnik.', $refused->getMessage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validPercents(): iterable
    {
        yield '1 %' => [1];
        yield '90 %' => [90];
    }

    #[DataProvider('validPercents')]
    public function testAPercentDiscountIsOneToNinetyPercent(int $percent): void
    {
        $value = DiscountValue::of(DiscountKind::Percent, $percent);

        self::assertSame(DiscountKind::Percent, $value->kind());
        self::assertSame($percent, $value->value());
        self::assertTrue($value->equals(DiscountValue::percent(PercentOff::of($percent))));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPercents(): iterable
    {
        yield '0 %' => [0];
        yield '91 %' => [91];
        yield 'negative' => [-10];
    }

    #[DataProvider('invalidPercents')]
    public function testAPercentOutsideOneToNinetyIsRefusedAtTheValue(int $percent): void
    {
        $refused = self::refusal(static fn () => DiscountValue::of(DiscountKind::Percent, $percent));

        self::assertSame(InvalidDiscountCode::VALUE, $refused->field);
        self::assertSame('Rabat procentowy to 1–90%.', $refused->getMessage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validAmounts(): iterable
    {
        yield '1 zł' => [100];
        yield '10 000 zł' => [1_000_000];
    }

    #[DataProvider('validAmounts')]
    public function testAnAmountDiscountIsOneToTenThousandZloty(int $grosze): void
    {
        $value = DiscountValue::of(DiscountKind::Amount, $grosze);

        self::assertSame(DiscountKind::Amount, $value->kind());
        self::assertSame($grosze, $value->value());
        self::assertTrue($value->equals(DiscountValue::amount(Money::of($grosze, Currency::PLN))));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidAmounts(): iterable
    {
        yield '0,99 zł' => [99];
        yield '0 zł' => [0];
        yield '10 000,01 zł' => [1_000_001];
    }

    #[DataProvider('invalidAmounts')]
    public function testAnAmountOutsideOneToTenThousandZlotyIsRefusedAtTheValue(int $grosze): void
    {
        $refused = self::refusal(static fn () => DiscountValue::of(DiscountKind::Amount, $grosze));

        self::assertSame(InvalidDiscountCode::VALUE, $refused->field);
        self::assertSame('Kwota rabatu to 1–10 000 zł.', $refused->getMessage());
    }

    public function testAPercentAndAnAmountOfTheSameNumberAreDifferentValues(): void
    {
        self::assertFalse(DiscountValue::of(DiscountKind::Percent, 50)->equals(DiscountValue::of(DiscountKind::Amount, 100)));
        self::assertFalse(DiscountValue::of(DiscountKind::Amount, 100)->equals(DiscountValue::of(DiscountKind::Percent, 50)));
    }

    /**
     * Q7: the smallest subtotal at which floor(S × p / 100) is at least one grosz, ceil(100 / p).
     *
     * @return iterable<string, array{int, int}>
     */
    public static function implicitMinimums(): iterable
    {
        yield '1 %' => [1, 100];
        yield '3 %' => [3, 34];
        yield '7 %' => [7, 15];
        yield '10 %' => [10, 10];
        yield '33 %' => [33, 4];
        yield '90 %' => [90, 2];
    }

    #[DataProvider('implicitMinimums')]
    public function testAPercentHasTheImplicitMinimumOfOneGroszOfDiscount(int $percent, int $minimum): void
    {
        $implicit = DiscountValue::of(DiscountKind::Percent, $percent)->implicitMinimumGross();

        self::assertSame($minimum, $implicit);
        self::assertGreaterThanOrEqual(1, intdiv($minimum * $percent, 100), 'the minimum gives a discount');
        self::assertSame(0, intdiv(($minimum - 1) * $percent, 100), 'one grosz less gives none');
    }

    public function testAnAmountHasNoImplicitMinimum(): void
    {
        self::assertNull(DiscountValue::of(DiscountKind::Amount, 5000)->implicitMinimumGross());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validMinimums(): iterable
    {
        yield '0,01 zł' => [1];
        yield '1 000 000 zł' => [100_000_000];
    }

    #[DataProvider('validMinimums')]
    public function testAMinimumIsUpToOneMillionZloty(int $grosze): void
    {
        self::assertSame($grosze, MinimumOrder::of($grosze)->gross());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidMinimums(): iterable
    {
        yield '0 zł' => [0];
        yield 'negative' => [-1];
        yield '1 000 000,01 zł' => [100_000_001];
    }

    #[DataProvider('invalidMinimums')]
    public function testAMinimumOutsideTheRangeIsRefused(int $grosze): void
    {
        $refused = self::refusal(static fn () => MinimumOrder::of($grosze));

        self::assertSame(InvalidDiscountCode::MINIMUM_ORDER, $refused->field);
        self::assertSame('Minimum to 0,01–1 000 000 zł.', $refused->getMessage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validLimits(): iterable
    {
        yield 'one use' => [1];
        yield 'a million uses' => [1_000_000];
    }

    #[DataProvider('validLimits')]
    public function testALimitIsOneToAMillionUses(int $limit): void
    {
        $usageLimit = UsageLimit::of($limit);

        self::assertSame($limit, $usageLimit->value());
        self::assertFalse($usageLimit->isReachedBy($limit - 1));
        self::assertTrue($usageLimit->isReachedBy($limit));
        self::assertTrue($usageLimit->isReachedBy($limit + 1));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'no use' => [0];
        yield 'negative' => [-1];
        yield 'a million and one' => [1_000_001];
    }

    #[DataProvider('invalidLimits')]
    public function testALimitOutsideTheRangeIsRefused(int $limit): void
    {
        $refused = self::refusal(static fn () => UsageLimit::of($limit));

        self::assertSame(InvalidDiscountCode::USAGE_LIMIT, $refused->field);
        self::assertSame('Limit to 1–1 000 000 użyć.', $refused->getMessage());
    }

    public function testAPeriodKeepsWholeSecondsInUtc(): void
    {
        $period = ValidityPeriod::of(new \DateTimeImmutable('2026-11-11T00:00:00.700000+01:00'), new \DateTimeImmutable('2026-11-21T00:00:00.300000+01:00'));

        self::assertSame('2026-11-10T23:00:00.000000+00:00', $period->startsAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertNotNull($period->endsAt());
        self::assertSame('2026-11-20T23:00:00.000000+00:00', $period->endsAt()->format('Y-m-d\TH:i:s.uP'));
    }

    public function testAPeriodIsValidFromItsStartUntilBeforeItsEnd(): void
    {
        $period = ValidityPeriod::of(new \DateTimeImmutable('2026-11-11T00:00:00Z'), new \DateTimeImmutable('2026-11-21T00:00:00Z'));

        self::assertFalse($period->isValidAt(new \DateTimeImmutable('2026-11-10T23:59:59.999999Z')));
        self::assertTrue($period->isValidAt(new \DateTimeImmutable('2026-11-11T00:00:00Z')));
        self::assertTrue($period->isValidAt(new \DateTimeImmutable('2026-11-20T23:59:59.999999Z')));
        self::assertFalse($period->isValidAt(new \DateTimeImmutable('2026-11-21T00:00:00Z')));
    }

    public function testAPeriodWithoutAnEndIsValidForeverFromItsStart(): void
    {
        $period = ValidityPeriod::of(new \DateTimeImmutable('2026-11-11T00:00:00Z'), null);

        self::assertNull($period->endsAt());
        self::assertFalse($period->isValidAt(new \DateTimeImmutable('2026-11-10T23:59:59Z')));
        self::assertTrue($period->isValidAt(new \DateTimeImmutable('9999-12-31T23:59:59Z')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptyPeriods(): iterable
    {
        yield 'the end at the start' => ['2026-11-11T00:00:00Z', '2026-11-11T00:00:00Z'];
        yield 'the end in the second of the start' => ['2026-11-11T00:00:00.100000Z', '2026-11-11T00:00:00.900000Z'];
        yield 'the end before the start' => ['2026-11-11T00:00:00Z', '2026-11-10T00:00:00Z'];
    }

    #[DataProvider('emptyPeriods')]
    public function testAnEndNotAfterTheStartIsRefusedAtTheEnd(string $startsAt, string $endsAt): void
    {
        $refused = self::refusal(static fn () => ValidityPeriod::of(new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt)));

        self::assertSame(InvalidDiscountCode::ENDS_AT, $refused->field);
        self::assertSame('Koniec musi być po początku.', $refused->getMessage());
    }

    public function testPeriodsOfTheSameSecondsAreEqual(): void
    {
        $period = ValidityPeriod::of(new \DateTimeImmutable('2026-11-11T00:00:00Z'), null);

        self::assertTrue($period->equals(ValidityPeriod::of(new \DateTimeImmutable('2026-11-11T01:00:00.500000+01:00'), null)));
        self::assertFalse($period->equals(ValidityPeriod::of(new \DateTimeImmutable('2026-11-11T00:00:00Z'), new \DateTimeImmutable('2026-11-12T00:00:00Z'))));
    }

    private static function refusal(callable $create): InvalidDiscountCode
    {
        try {
            $create();
        } catch (InvalidDiscountCode $refused) {
            return $refused;
        }

        self::fail('The value must be refused.');
    }
}
