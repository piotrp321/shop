<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\Ean;
use App\Catalog\Domain\Product\Sku;
use App\Tests\Support\InputConformance\InputValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SKU and the EAN of a variant (PRODUCT-DATA, "Pola i reguły"): optional codes of a closed alphabet, checked in the
 * order of the rule of texts (T1 on the value as it came, T2 trims the ends, T3 makes a text without a visible character
 * no code) and only then by their own format. optional() is the write (the request, the seed, the generator); stored()
 * reads a stored code as it is, without the rule (spec api-input-contract, E7).
 */
final class VariantCodesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenAnywhere(): iterable
    {
        yield 'a tab at the end' => ["AB-1\t"];
        yield 'a tab at the start' => ["\tAB-1"];
        yield 'a tab inside' => ["AB\t1"];
        yield 'a line feed at the end' => ["AB-1\n"];
        yield 'a carriage return at the end' => ["AB-1\r"];
        yield 'NEL at the edge' => ["\u{0085}AB-1"];
        yield 'a line separator at the end' => ["AB-1\u{2028}"];
        yield 'a paragraph separator at the start' => ["\u{2029}AB-1"];
        yield 'NUL' => ["AB\x00-1"];
        yield 'invalid UTF-8' => ["AB\xC3\x28"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blank(): iterable
    {
        yield 'nothing' => [''];
        yield from InputValues::blankUnicodeTexts();
        yield 'zero width' => ["\u{200B}\u{2060}\u{FEFF}\u{00AD}"];
        yield 'a joiner' => ["\u{200D}"];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function skusWithEdges(): iterable
    {
        yield 'ASCII spaces' => [' ab-1 ', 'AB-1'];
        yield 'no-break spaces' => ["\u{00A0}AB-1\u{00A0}", 'AB-1'];
        yield 'an em space' => ["\u{2003}AB-1\u{2003}", 'AB-1'];
        yield 'a narrow no-break space' => ["\u{202F}AB-1\u{202F}", 'AB-1'];
        yield 'an ideographic space' => ["\u{3000}AB-1\u{3000}", 'AB-1'];
        yield 'a Mongolian vowel separator' => ["\u{180E}AB-1\u{180E}", 'AB-1'];
        yield 'a zero width space' => ["\u{200B}AB-1\u{200B}", 'AB-1'];
        yield 'a word joiner' => ["\u{2060}AB-1\u{2060}", 'AB-1'];
        yield 'a byte order mark' => ["\u{FEFF}AB-1\u{FEFF}", 'AB-1'];
        yield 'a soft hyphen' => ["\u{00AD}AB-1\u{00AD}", 'AB-1'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSkuFormats(): iterable
    {
        yield 'a space inside' => ['AB 1'];
        yield 'a dash first' => ['-AB1'];
        yield 'a dot first' => ['.AB1'];
        yield 'an underscore first' => ['_AB1'];
        yield 'a slash first' => ['/AB1'];
        yield 'a Polish letter' => ['ŻÓŁW-1'];
        yield 'a comma' => ['AB,1'];
        yield 'a plus' => ['AB+1'];
        yield 'a colon' => ['AB:1'];
        yield 'a zero width space inside' => ["AB\u{200B}1"];
        yield 'a no-break space inside' => ["AB\u{00A0}1"];
        yield '41 characters' => [str_repeat('A', 41)];
        yield '41 characters once trimmed' => [' '.str_repeat('a', 41).' '];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badEanFormats(): iterable
    {
        yield 'nine digits' => ['123456789'];
        yield 'ten digits' => ['1234567890'];
        yield 'eleven digits' => ['12345678901'];
        yield 'fifteen digits' => ['123456789012345'];
        yield 'letters' => ['590123412345A'];
        yield 'a space inside' => ['5901234 123457'];
        yield 'a dash inside' => ['590-1234123457'];
        yield 'a wrong check digit of 8' => ['59012345'];
        yield 'a wrong check digit of 12' => ['012345678906'];
        yield 'a wrong check digit of 13' => ['5901234123458'];
        yield 'a wrong check digit of 14' => ['15901234123455'];
        yield 'a fullwidth digit' => ["590123412345\u{FF17}"];
    }

    #[DataProvider('forbiddenAnywhere')]
    public function testSkuRefusesForbiddenCharactersAlsoAtTheEdgesBeforeTheyAreTrimmed(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sku must not contain forbidden characters.');

        Sku::optional($value);
    }

    #[DataProvider('forbiddenAnywhere')]
    public function testEanRefusesForbiddenCharactersAlsoAtTheEdgesBeforeTheyAreTrimmed(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ean must not contain forbidden characters.');

        Ean::optional($value);
    }

    #[DataProvider('skusWithEdges')]
    public function testSkuIsTrimmedByTheRuleOfTextsAndWrittenInCapitals(string $value, string $expected): void
    {
        self::assertSame($expected, Sku::optional($value)?->value());
    }

    public function testSkuTakesTheLettersAsciiOnlyDigitsAndTheThreeSignsAndStartsWithALetterOrADigit(): void
    {
        self::assertSame('AZ09-_./X', Sku::optional('az09-_./x')?->value());
        self::assertSame('0', Sku::optional('0')?->value());
        self::assertSame('9A', Sku::optional('9a')?->value());
        self::assertSame('NAMIOT-HALNY-4/CZERWONY', Sku::optional('Namiot-Halny-4/czerwony')?->value());
    }

    public function testSkuOf40CharactersIsTheLongest(): void
    {
        self::assertSame(str_repeat('A', 40), Sku::optional("\u{00A0}".str_repeat('a', 40)."\u{200B}")?->value());
    }

    #[DataProvider('badSkuFormats')]
    public function testSkuOfAnotherFormatIsRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sku must have from 1 to 40 characters');

        Sku::optional($value);
    }

    #[DataProvider('blank')]
    public function testASkuWithoutAVisibleCharacterIsNoCode(string $value): void
    {
        self::assertNull(Sku::optional($value));
    }

    #[DataProvider('blank')]
    public function testAnEanWithoutAVisibleCharacterIsNoCode(string $value): void
    {
        self::assertNull(Ean::optional($value));
    }

    public function testSkuReadFromTheDatabaseIsNotChecked(): void
    {
        self::assertSame('ab 1', Sku::stored('ab 1')->value());
        self::assertSame(str_repeat('a', 41), Sku::stored(str_repeat('a', 41))->value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validEans(): iterable
    {
        yield 'EAN-8' => ['59012344'];
        yield 'UPC-A of 12 digits' => ['012345678905'];
        yield 'EAN-13' => ['5901234123457'];
        yield 'GTIN-14' => ['15901234123454'];
        yield 'EAN-13 with a check digit of 0' => ['0501234567890'];
        yield 'a prefix 2 EAN-13 of the demo data' => ['2000000000008'];
    }

    #[DataProvider('validEans')]
    public function testAnEanOf8And12And13And14DigitsWithTheRightCheckDigitIsTaken(string $value): void
    {
        self::assertSame($value, Ean::optional($value)?->value());
    }

    public function testAnEanIsTrimmedByTheRuleOfTexts(): void
    {
        self::assertSame('5901234123457', Ean::optional("\u{00A0} 5901234123457\u{200B}\u{3000}")?->value());
        self::assertSame('59012344', Ean::optional("\u{FEFF}59012344\u{2003}")?->value());
    }

    #[DataProvider('badEanFormats')]
    public function testAnEanOfAnotherFormatOrWithAWrongCheckDigitIsRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ean must have 8, 12, 13 or 14 digits with the check digit of GS1.');

        Ean::optional($value);
    }

    public function testEanReadFromTheDatabaseIsNotChecked(): void
    {
        self::assertSame('123', Ean::stored('123')->value());
        self::assertSame('5901234123458', Ean::stored('5901234123458')->value());
    }

    public function testAStoredEanWithoutADigitCodeHasNoGtinAndIsNotTakenForRestrictedCirculation(): void
    {
        // The write never gives such a code; the read of a stored row must still be able to answer.
        self::assertSame('', Ean::stored('abc')->gtin());
    }

    public function testTheGtinOfAnOrdinaryEanIsTheEanAndOfARestrictedCirculationNumberIsEmpty(): void
    {
        self::assertSame('5901234123457', Ean::optional('5901234123457')?->gtin());
        self::assertSame('', Ean::optional('2000000000008')?->gtin());
        self::assertTrue(Ean::optional('2000000000008')?->isRestrictedCirculation());
        self::assertFalse(Ean::optional('5901234123457')?->isRestrictedCirculation());
    }
}
