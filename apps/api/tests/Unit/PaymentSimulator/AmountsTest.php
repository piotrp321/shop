<?php

declare(strict_types=1);

namespace App\Tests\Unit\PaymentSimulator;

use App\PaymentSimulator\Amounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountsTest extends TestCase
{
    #[DataProvider('formats')]
    public function testFormatsMinorUnitsAsADecimalStringWithoutFloats(int $minor, string $expected): void
    {
        self::assertSame($expected, Amounts::format($minor));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function formats(): iterable
    {
        yield 'zero' => [0, '0.00'];
        yield 'one grosz' => [1, '0.01'];
        yield 'ten groszy' => [10, '0.10'];
        yield 'a round amount' => [12_300, '123.00'];
        yield 'the vector amount' => [12_345, '123.45'];
        yield 'beyond a float' => [9_007_199_254_740_993, '90071992547409.93'];
    }

    #[DataProvider('parsable')]
    public function testParsesTheStrictDecimalFormat(string $text, int $expected): void
    {
        self::assertSame($expected, Amounts::parse($text));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function parsable(): iterable
    {
        yield 'zero' => ['0.00', 0];
        yield 'one grosz' => ['0.01', 1];
        yield 'the vector amount' => ['123.45', 12_345];
        yield 'beyond a float' => ['90071992547409.93', 9_007_199_254_740_993];
    }

    #[DataProvider('unparsable')]
    public function testRefusesEverythingElse(string $text): void
    {
        self::assertNull(Amounts::parse($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unparsable(): iterable
    {
        yield 'one decimal place' => ['1.5'];
        yield 'no decimal places' => ['12'];
        yield 'three decimal places' => ['1.234'];
        yield 'negative' => ['-1.00'];
        yield 'leading zero' => ['01.00'];
        yield 'a comma' => ['1,00'];
        yield 'a space' => [' 1.00'];
        yield 'empty' => [''];
        yield 'a trailing newline' => ["1.00\n"];
        yield 'beyond an int' => ['99999999999999999999.00'];
    }
}
