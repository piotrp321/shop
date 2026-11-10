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
 * The value objects apply the rule of ProductText (the same as the validator of the write endpoints): they trim
 * Unicode whitespace and refuse forbidden characters, looked for in the value as it came.
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
    }

    #[DataProvider('forbidden')]
    public function testProductNameRefusesForbiddenCharacters(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductName must not contain forbidden characters.');

        new ProductName($value);
    }

    public function testProductNameTrimsUnicodeWhitespaceAndCountsTheTrimmedLength(): void
    {
        self::assertSame('Buty', new ProductName("\u{00A0} Buty\u{3000}")->value());
        self::assertSame(ProductName::MAX_LENGTH, mb_strlen(new ProductName("\u{00A0}".str_repeat('ż', ProductName::MAX_LENGTH)."\u{00A0}")->value()));
    }

    public function testProductNameOfOnlyUnicodeWhitespaceIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductName must not be empty.');

        new ProductName("\u{00A0}\u{2003}");
    }

    public function testProductNameTooLongIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductName(str_repeat('a', ProductName::MAX_LENGTH + 1));
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
    }

    #[DataProvider('forbiddenInADescription')]
    public function testShortDescriptionRefusesForbiddenCharacters(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ShortDescription must not contain forbidden characters.');

        new ShortDescription($value);
    }

    public function testShortDescriptionKeepsLineFeedsInside(): void
    {
        self::assertSame("Pierwsza linia\nDruga", new ShortDescription("Pierwsza linia\nDruga")->value());
    }

    public function testShortDescriptionTrimsUnicodeWhitespaceAndOfOnlyWhitespaceIsEmpty(): void
    {
        self::assertSame('Opis', new ShortDescription("\u{00A0}\nOpis\u{3000}\n")->value());
        self::assertSame('', new ShortDescription("\u{00A0}\u{2003}\n ")->value());
    }

    public function testShortDescriptionTooLongIsRefusedAfterTheTrim(): void
    {
        self::assertSame(ShortDescription::MAX_LENGTH, mb_strlen(new ShortDescription(str_repeat('a', ShortDescription::MAX_LENGTH)."\u{00A0}")->value()));

        $this->expectException(\InvalidArgumentException::class);

        new ShortDescription(str_repeat('a', ShortDescription::MAX_LENGTH + 1));
    }

    #[DataProvider('forbidden')]
    public function testProductImageRefusesForbiddenCharactersInTheAlt(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image alt text must not contain forbidden characters.');

        new ProductImage(ImageKey::fromString(self::KEY), $value, 0);
    }

    public function testProductImageTrimsTheAltAndRefusesOneOfOnlyUnicodeWhitespace(): void
    {
        self::assertSame('Buty z boku', new ProductImage(ImageKey::fromString(self::KEY), "\u{00A0}Buty z boku\u{00A0}", 0)->alt());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image alt text must not be empty.');

        new ProductImage(ImageKey::fromString(self::KEY), "\u{00A0}\u{2003}", 0);
    }

    public function testProductImageRefusesAnAltTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductImage(ImageKey::fromString(self::KEY), str_repeat('a', ProductImage::MAX_ALT_LENGTH + 1), 0);
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
