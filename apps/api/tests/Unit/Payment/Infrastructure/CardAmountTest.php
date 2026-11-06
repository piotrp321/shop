<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Infrastructure;

use App\Payment\Infrastructure\Provider\CardAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The amounts of demo-card ("123.45") from and to minor units, by integer arithmetic only.
 */
final class CardAmountTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function amounts(): iterable
    {
        yield 'one grosz' => [1, '0.01'];
        yield 'under one złoty' => [99, '0.99'];
        yield 'whole złoty' => [100, '1.00'];
        yield 'ten groszy' => [1_210, '12.10'];
        yield 'an order' => [12_345, '123.45'];
        yield 'a large one' => [1_000_000_007, '10000000.07'];
    }

    #[DataProvider('amounts')]
    public function testFormatsMinorUnitsWithTwoDecimals(int $minorUnits, string $text): void
    {
        self::assertSame($text, CardAmount::format($minorUnits));
    }

    #[DataProvider('amounts')]
    public function testParsesTheSameText(int $minorUnits, string $text): void
    {
        self::assertSame($minorUnits, CardAmount::parse($text));
    }

    public function testZeroIsZero(): void
    {
        self::assertSame('0.00', CardAmount::format(0));
        self::assertSame(0, CardAmount::parse('0.00'));
    }

    public function testANegativeAmountIsNotFormatted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CardAmount::format(-1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTexts(): iterable
    {
        yield 'one decimal' => ['1.5'];
        yield 'three decimals' => ['1.505'];
        yield 'no decimals' => ['1'];
        yield 'negative' => ['-1.00'];
        yield 'a leading zero' => ['01.00'];
        yield 'a plus sign' => ['+1.00'];
        yield 'a comma' => ['1,00'];
        yield 'spaces' => [' 1.00'];
        yield 'a newline' => ["1.00\n"];
        yield 'an exponent' => ['1e2.00'];
        yield 'empty' => [''];
        yield 'beyond an int' => ['92233720368547758.08'];
    }

    #[DataProvider('invalidTexts')]
    public function testAnythingElseIsNoAmount(string $text): void
    {
        self::assertNull(CardAmount::parse($text));
    }
}
