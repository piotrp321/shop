<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\InvalidVariantAxes;
use App\Catalog\Domain\Product\InvalidVariantValues;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The value objects apply the rule of texts (App\Shared\Domain\Text, the same as the validator of the write endpoints):
 * they trim Unicode whitespace and invisible characters from the ends, take a text without a visible character for
 * empty and refuse forbidden characters, looked for in the value as it came. stored() reads a stored text as it
 * is (spec api-input-contract, E7).
 */
final class ProductTextRulesInValueObjectsTest extends TestCase
{
    private const string KEY = 'originals/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg';

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbidden(): iterable
    {
        yield 'NUL' => ["a\x00b"];
        yield 'BEL' => ["a\x07b"];
        yield 'line separator' => ["a\u{2028}b"];
        yield 'line feed' => ["a\nb"];
        yield 'NUL after a trimmable end' => ["a\x00\u{00A0}"];
        yield 'invalid UTF-8' => ["a\xC3\x28"];
        yield 'a C1 control character' => ["a\u{9F}b"];
        yield 'U+0080' => ["a\u{80}b"];
        yield 'a line separator at the end' => ["ab\u{2028}"];
    }

    /**
     * Texts without a visible character (T3).
     *
     * @return iterable<string, array{string}>
     */
    public static function invisible(): iterable
    {
        yield 'U+200B' => ["\u{200B}"];
        yield 'U+FEFF' => ["\u{FEFF}"];
        yield 'U+00AD' => ["\u{AD}"];
        yield 'U+2060' => ["\u{2060}"];
        yield 'U+3164' => ["\u{3164}"];
        yield 'joiners and marks of direction' => ["\u{200D}\u{200E}\u{202E}"];
        yield 'white space and zero width spaces' => [" \u{200B}\u{A0}\u{200B} "];
    }

    /**
     * Texts stored before the rule of texts that the rule refuses: read as they are.
     *
     * @return iterable<string, array{string}>
     */
    public static function storedOutsideTheRule(): iterable
    {
        yield 'U+200B only' => ["\u{200B}"];
        yield 'a tab inside' => ["Bu\tty"];
        yield 'U+009F inside' => ["Bu\u{9F}ty"];
        yield 'spaces at the ends' => [' Buty '];
    }

    #[DataProvider('forbidden')]
    public function testProductNameRefusesForbiddenCharacters(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductName must not contain forbidden characters.');

        ProductName::of($value);
    }

    public function testProductNameTrimsUnicodeWhitespaceAndCountsTheTrimmedLength(): void
    {
        self::assertSame('Buty', ProductName::of("\u{00A0} Buty\u{3000}")->value());
        self::assertSame(ProductName::MAX_LENGTH, mb_strlen(ProductName::of("\u{00A0}".str_repeat('ż', ProductName::MAX_LENGTH)."\u{00A0}")->value()));
    }

    public function testProductNameOfOnlyUnicodeWhitespaceIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductName must not be empty.');

        ProductName::of("\u{00A0}\u{2003}");
    }

    #[DataProvider('invisible')]
    public function testProductNameWithoutAVisibleCharacterIsEmpty(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductName must not be empty.');

        ProductName::of($value);
    }

    public function testProductNameTrimsInvisibleCharactersAndCountsTheTrimmedLength(): void
    {
        self::assertSame('Buty', ProductName::of("\u{200B}\u{FEFF}Buty\u{2060}\u{AD}")->value());
        self::assertSame("Bu\u{200B}ty", ProductName::of("Bu\u{200B}ty")->value());
        self::assertSame(ProductName::MAX_LENGTH, mb_strlen(ProductName::of("\u{200B}".str_repeat('ż', ProductName::MAX_LENGTH)."\u{200B}")->value()));
    }

    public function testProductNameKeepsAFlagAndAnEmojiWithAJoiner(): void
    {
        $flag = "Buty \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";
        self::assertSame($flag, ProductName::of($flag)->value());
        self::assertSame("Buty \u{1F469}\u{200D}\u{1F4BB}", ProductName::of("Buty \u{1F469}\u{200D}\u{1F4BB}")->value());
    }

    #[DataProvider('storedOutsideTheRule')]
    public function testStoredTextsAreReadWithoutTheRule(string $stored): void
    {
        self::assertSame($stored, ProductName::stored($stored)->value());
        self::assertSame($stored, ShortDescription::stored($stored)->value());
        self::assertSame($stored, ProductImage::stored(ImageKey::fromString(self::KEY), $stored, 0)->alt());
        self::assertSame([$stored], VariantAxes::stored([$stored])->names());
        self::assertSame([$stored], VariantValues::stored([$stored])->toList());
    }

    public function testProductNameTooLongIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductName::of(str_repeat('a', ProductName::MAX_LENGTH + 1));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenInADescription(): iterable
    {
        yield 'NUL' => ["a\x00b"];
        yield 'BEL' => ["a\x07b"];
        yield 'line separator' => ["a\u{2028}b"];
        yield 'carriage return' => ["a\r\nb"];
        yield 'invalid UTF-8' => ["a\xC3\x28"];
        yield 'a C1 control character' => ["a\u{9F}b"];
        yield 'a line separator at the end' => ["ab\u{2028}"];
    }

    #[DataProvider('forbiddenInADescription')]
    public function testShortDescriptionRefusesForbiddenCharacters(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ShortDescription must not contain forbidden characters.');

        ShortDescription::of($value);
    }

    public function testShortDescriptionKeepsLineFeedsInside(): void
    {
        self::assertSame("Pierwsza linia\nDruga", ShortDescription::of("Pierwsza linia\nDruga")->value());
    }

    public function testShortDescriptionTrimsUnicodeWhitespaceAndOfOnlyWhitespaceIsEmpty(): void
    {
        self::assertSame('Opis', ShortDescription::of("\u{00A0}\nOpis\u{3000}\n")->value());
        self::assertSame('', ShortDescription::of("\u{00A0}\u{2003}\n ")->value());
    }

    #[DataProvider('invisible')]
    public function testShortDescriptionWithoutAVisibleCharacterIsEmpty(string $value): void
    {
        self::assertSame('', ShortDescription::of($value)->value());
        self::assertSame('', ShortDescription::of("\n".$value."\n")->value());
    }

    public function testShortDescriptionTrimsInvisibleCharactersAndKeepsLineFeedsInside(): void
    {
        self::assertSame("Opis\nDruga", ShortDescription::of("\u{200B}Opis\nDruga\u{FEFF}")->value());
        self::assertSame(ShortDescription::MAX_LENGTH, mb_strlen(ShortDescription::of("\u{200B}".str_repeat('a', ShortDescription::MAX_LENGTH)."\u{200B}")->value()));
    }

    public function testShortDescriptionTooLongIsRefusedAfterTheTrim(): void
    {
        self::assertSame(ShortDescription::MAX_LENGTH, mb_strlen(ShortDescription::of(str_repeat('a', ShortDescription::MAX_LENGTH)."\u{00A0}")->value()));

        $this->expectException(\InvalidArgumentException::class);

        ShortDescription::of(str_repeat('a', ShortDescription::MAX_LENGTH + 1));
    }

    #[DataProvider('forbidden')]
    public function testProductImageRefusesForbiddenCharactersInTheAlt(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image alt text must not contain forbidden characters.');

        ProductImage::of(ImageKey::fromString(self::KEY), $value, 0);
    }

    public function testProductImageTrimsTheAltAndRefusesOneOfOnlyUnicodeWhitespace(): void
    {
        self::assertSame('Buty z boku', ProductImage::of(ImageKey::fromString(self::KEY), "\u{00A0}Buty z boku\u{00A0}", 0)->alt());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image alt text must not be empty.');

        ProductImage::of(ImageKey::fromString(self::KEY), "\u{00A0}\u{2003}", 0);
    }

    #[DataProvider('invisible')]
    public function testProductImageRefusesAnAltWithoutAVisibleCharacter(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image alt text must not be empty.');

        ProductImage::of(ImageKey::fromString(self::KEY), $value, 0);
    }

    public function testProductImageTrimsInvisibleCharactersFromTheAlt(): void
    {
        self::assertSame('Buty z boku', ProductImage::of(ImageKey::fromString(self::KEY), "\u{200B}Buty z boku\u{FEFF}", 0)->alt());
    }

    public function testProductImageRefusesAnAltTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductImage::of(ImageKey::fromString(self::KEY), str_repeat('a', ProductImage::MAX_ALT_LENGTH + 1), 0);
    }

    #[DataProvider('forbidden')]
    public function testVariantAxesRefuseForbiddenCharacters(string $value): void
    {
        $this->expectExceptionObject(InvalidVariantAxes::nameWithForbiddenCharacters());

        VariantAxes::fromList([$value]);
    }

    public function testVariantAxesTrimUnicodeWhitespaceAndRefuseOnlyWhitespace(): void
    {
        self::assertSame(['Kolor', 'Rozmiar'], VariantAxes::fromList(["\u{00A0}Kolor\u{00A0}", "\u{3000}Rozmiar"])->names());

        $this->expectExceptionObject(InvalidVariantAxes::nameLengthOutOfRange());

        VariantAxes::fromList(["\u{00A0}\u{2003}"]);
    }

    #[DataProvider('invisible')]
    public function testVariantAxesRefuseANameWithoutAVisibleCharacter(string $value): void
    {
        $this->expectExceptionObject(InvalidVariantAxes::nameLengthOutOfRange());

        VariantAxes::fromList([$value]);
    }

    public function testVariantAxesTrimInvisibleCharactersBeforeComparingTheNames(): void
    {
        self::assertSame(['Kolor', 'Rozmiar'], VariantAxes::fromList(["\u{200B}Kolor\u{FEFF}", "Rozmiar\u{2060}"])->names());

        $this->expectExceptionObject(InvalidVariantAxes::nameRepeated());

        VariantAxes::fromList(['Kolor', "\u{200B}kolor"]);
    }

    #[DataProvider('invisible')]
    public function testVariantValuesRefuseAValueWithoutAVisibleCharacter(string $value): void
    {
        $this->expectExceptionObject(InvalidVariantValues::lengthOutOfRange());

        VariantValues::fromList([$value]);
    }

    public function testVariantValuesTrimInvisibleCharacters(): void
    {
        self::assertSame(['Czerwony', 'XL'], VariantValues::fromList(["\u{200B}Czerwony\u{FEFF}", "XL\u{AD}"])->toList());
        self::assertSame(['czerwony'], VariantValues::fromList(["\u{200B}Czerwony"])->comparisonKey());
    }

    #[DataProvider('forbidden')]
    public function testVariantValuesRefuseForbiddenCharacters(string $value): void
    {
        $this->expectExceptionObject(InvalidVariantValues::forbiddenCharacters());

        VariantValues::fromList([$value]);
    }

    public function testVariantValuesTrimUnicodeWhitespaceAndRefuseOnlyWhitespace(): void
    {
        self::assertSame(['Czerwony', 'XL'], VariantValues::fromList(["\u{00A0}Czerwony\u{00A0}", "\u{3000}XL"])->toList());

        $this->expectExceptionObject(InvalidVariantValues::lengthOutOfRange());

        VariantValues::fromList(["\u{00A0}\u{2003}"]);
    }

    public function testForbiddenCharactersAreFoundBeforeTheTrim(): void
    {
        // a trim would drop the trailing NUL; the value must be refused instead of saved cut
        $this->expectExceptionObject(InvalidVariantValues::forbiddenCharacters());

        VariantValues::fromList(["XL\x00"]);
    }
}
