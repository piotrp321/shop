<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\ProductText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenEverywhere(): iterable
    {
        yield 'NUL' => ["a\x00b"];
        yield 'BEL' => ["a\x07b"];
        yield 'tab' => ["a\tb"];
        yield 'carriage return' => ["a\rb"];
        yield 'escape' => ["a\x1Bb"];
        yield 'unit separator' => ["a\x1Fb"];
        yield 'DEL' => ["a\x7Fb"];
        yield 'next line U+0085' => ["a\u{0085}b"];
        yield 'line separator U+2028' => ["a\u{2028}b"];
        yield 'paragraph separator U+2029' => ["a\u{2029}b"];
        yield 'NUL at the end' => ["abc\x00"];
        yield 'tab at the start' => ["\tabc"];
        yield 'lone continuation byte' => ["a\x80b"];
        yield 'truncated sequence' => ["a\xC3"];
        yield 'overlong sequence' => ["a\xC0\xAFb"];
        yield 'encoded surrogate' => ["a\xED\xA0\x80b"];
        yield 'only invalid bytes' => ["\xFF"];
    }

    #[DataProvider('forbiddenEverywhere')]
    public function testForbiddenCharactersAreFoundInEveryText(string $value): void
    {
        self::assertTrue(ProductText::hasForbiddenCharacters($value));
        self::assertTrue(ProductText::hasForbiddenCharacters($value, allowLineFeed: true));
    }

    public function testALineFeedIsForbiddenUnlessAllowed(): void
    {
        self::assertTrue(ProductText::hasForbiddenCharacters("a\nb"));
        self::assertFalse(ProductText::hasForbiddenCharacters("a\nb", allowLineFeed: true));
        self::assertFalse(ProductText::hasForbiddenCharacters("\n", allowLineFeed: true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowed(): iterable
    {
        yield 'empty' => [''];
        yield 'plain' => ['Buty biegowe'];
        yield 'Polish letters' => ['Żółć gęślą jaźń'];
        yield 'emoji' => ["Namiot \u{1F3D5}"];
        yield 'non-breaking space' => ["a\u{00A0}b"];
        yield 'ideographic space' => ["a\u{3000}b"];
        yield 'zero width space' => ["a\u{200B}b"];
        yield 'DEL neighbour U+0080' => ["a\u{0080}b"];
        yield 'U+0084' => ["a\u{0084}b"];
        yield 'U+0086' => ["a\u{0086}b"];
        yield 'U+2027' => ["a\u{2027}b"];
        yield 'U+202A' => ["a\u{202A}b"];
    }

    #[DataProvider('allowed')]
    public function testOtherCharactersAreNotForbidden(string $value): void
    {
        self::assertFalse(ProductText::hasForbiddenCharacters($value));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function trimmed(): iterable
    {
        yield 'ASCII spaces' => ['  Buty  ', 'Buty'];
        yield 'non-breaking spaces' => ["\u{00A0}\u{00A0}Buty\u{00A0}", 'Buty'];
        yield 'em space and ideographic space' => ["\u{2003}\u{3000}Buty\u{3000}\u{2003}", 'Buty'];
        yield 'narrow no-break space' => ["\u{202F}Buty\u{202F}", 'Buty'];
        yield 'only non-breaking spaces' => ["\u{00A0}\u{00A0}", ''];
        yield 'only ASCII spaces' => ['   ', ''];
        yield 'inside stays' => ["a\u{00A0} b", "a\u{00A0} b"];
        yield 'line feed at the ends' => ["\nButy\n", 'Buty'];
        yield 'Polish letters' => [" \u{00A0}Łódź\u{00A0} ", 'Łódź'];
        yield 'nothing to trim' => ['Buty', 'Buty'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('trimmed')]
    public function testUnicodeWhitespaceIsTrimmedFromTheEnds(string $value, string $expected): void
    {
        self::assertSame($expected, ProductText::trim($value));
    }

    public function testTrimLeavesInvalidUtf8UntouchedForTheCallerToRefuse(): void
    {
        self::assertSame("  a\xC3", ProductText::trim("  a\xC3"));
    }

    public function testTrimmedLengthCountsCharactersNotBytes(): void
    {
        self::assertSame(4, ProductText::length("\u{00A0}Łódź\u{00A0}"));
    }
}
