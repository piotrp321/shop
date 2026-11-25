<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\ProductBrand;
use App\Catalog\Domain\Product\ProductDescription;
use App\Catalog\Domain\Product\ProductParameter;
use App\Catalog\Domain\Product\ProductParameters;
use App\Tests\Support\InputConformance\InputValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The brand, the description and the parameters of a product (PRODUCT-DATA, "Pola i reguły"): texts under the rule of
 * texts (Text) with limits of their own. of() and create() are the write (the request, the seed, the generator);
 * stored() reads a stored text as it is, without the rule (spec api-input-contract, E7).
 */
final class ProductContentTextsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenInALine(): iterable
    {
        yield 'a tab at the end' => ["Hallen\t"];
        yield 'a tab inside' => ["Hal\tlen"];
        yield 'a line feed' => ["Hal\nlen"];
        yield 'a carriage return' => ["Hallen\r"];
        yield 'NEL at the edge' => ["\u{0085}Hallen"];
        yield 'a line separator at the end' => ["Hallen\u{2028}"];
        yield 'a paragraph separator at the start' => ["\u{2029}Hallen"];
        yield 'NUL' => ["Hal\x00len"];
        yield 'invalid UTF-8' => ["Hal\xC3\x28len"];
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
     * @return iterable<string, array{string}>
     */
    public static function forbiddenInADescription(): iterable
    {
        yield 'a carriage return' => ["Pierwszy\r\nDrugi"];
        yield 'a carriage return at the end' => ["Opis\r"];
        yield 'a tab' => ["Opis\tdalej"];
        yield 'NUL' => ["Opis\x00"];
        yield 'a line separator at the end' => ["Opis\u{2028}"];
        yield 'invalid UTF-8' => ["Opis\xC3\x28"];
    }

    #[DataProvider('forbiddenInALine')]
    public function testBrandRefusesForbiddenCharactersAlsoAtTheEdges(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductBrand must not contain forbidden characters.');

        ProductBrand::of($value);
    }

    public function testBrandIsTrimmedByTheRuleOfTextsAndKeepsTheInside(): void
    {
        self::assertSame('Hallen', ProductBrand::of("\u{00A0}\u{200B}Hallen\u{3000}\u{FEFF}")->value());
        self::assertSame("Hal\u{200B}len", ProductBrand::of("Hal\u{200B}len")->value());
        self::assertSame("Zażółć \u{1F469}\u{200D}\u{1F4BB}", ProductBrand::of("Zażółć \u{1F469}\u{200D}\u{1F4BB}")->value());
    }

    #[DataProvider('blank')]
    public function testBrandWithoutAVisibleCharacterIsNoBrand(string $value): void
    {
        self::assertSame('', ProductBrand::of($value)->value());
    }

    public function testBrandHasAtMost60CharactersOnceTrimmed(): void
    {
        self::assertSame(60, mb_strlen(ProductBrand::of("\u{00A0}".str_repeat('ż', 60)."\u{200B}")->value()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductBrand must have at most 60 characters.');

        ProductBrand::of(str_repeat('a', 61));
    }

    public function testBrandReadFromTheDatabaseIsNotChecked(): void
    {
        self::assertSame("Hallen\t", ProductBrand::stored("Hallen\t")->value());
        self::assertSame(str_repeat('a', 61), ProductBrand::stored(str_repeat('a', 61))->value());
    }

    #[DataProvider('forbiddenInADescription')]
    public function testDescriptionRefusesForbiddenCharacters(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductDescription must not contain forbidden characters.');

        ProductDescription::of($value);
    }

    public function testDescriptionKeepsLineFeedsInsideAndTrimsThemAtTheEdges(): void
    {
        self::assertSame("Pierwszy akapit.\n\nDrugi akapit.", ProductDescription::of("\n \u{00A0}Pierwszy akapit.\n\nDrugi akapit.\u{3000}\n")->value());
    }

    #[DataProvider('blank')]
    public function testDescriptionWithoutAVisibleCharacterIsNoDescription(string $value): void
    {
        self::assertSame('', ProductDescription::of($value)->value());
        self::assertSame('', ProductDescription::of("\n".$value."\n")->value());
    }

    public function testDescriptionHasAtMost5000CharactersOnceTrimmed(): void
    {
        self::assertSame(5000, mb_strlen(ProductDescription::of("\n".str_repeat('ż', 5000)."\u{00A0}")->value()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductDescription must have at most 5000 characters.');

        ProductDescription::of(str_repeat('a', 5001));
    }

    public function testDescriptionReadFromTheDatabaseIsNotChecked(): void
    {
        self::assertSame("Opis\r", ProductDescription::stored("Opis\r")->value());
    }

    public function testAParameterIsTrimmedByTheRuleOfTexts(): void
    {
        $parameter = ProductParameter::of("\u{00A0}Waga\u{200B}", "\u{3000}250 g\u{FEFF}");

        self::assertSame('Waga', $parameter->name());
        self::assertSame('250 g', $parameter->value());
    }

    #[DataProvider('forbiddenInALine')]
    public function testAParameterRefusesForbiddenCharactersInTheNameAndInTheValue(string $value): void
    {
        foreach ([[$value, 'ok', 'name'], ['Nazwa', $value, 'value']] as [$name, $text, $field]) {
            try {
                ProductParameter::of($name, $text);
                self::fail('Expected a refusal.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(\sprintf('ProductParameter %s must not contain forbidden characters.', $field), $e->getMessage());
            }
        }
    }

    #[DataProvider('blank')]
    public function testAParameterWithoutAVisibleCharacterInTheNameOrTheValueIsRefused(string $value): void
    {
        foreach ([[$value, 'ok', 'name must have from 1 to 60'], ['Nazwa', $value, 'value must have from 1 to 200']] as [$name, $text, $message]) {
            try {
                ProductParameter::of($name, $text);
                self::fail('Expected a refusal.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(\sprintf('ProductParameter %s characters.', $message), $e->getMessage());
            }
        }
    }

    public function testTheLimitsOfAParameterCountTheTrimmedLength(): void
    {
        self::assertSame(60, mb_strlen(ProductParameter::of("\u{00A0}".str_repeat('ż', 60), 'x')->name()));
        self::assertSame(200, mb_strlen(ProductParameter::of('x', str_repeat('ż', 200)."\u{00A0}")->value()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductParameter name must have from 1 to 60 characters.');

        ProductParameter::of(str_repeat('a', 61), 'x');
    }

    public function testAParameterValueOver200CharactersIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductParameter value must have from 1 to 200 characters.');

        ProductParameter::of('x', str_repeat('a', 201));
    }

    public function testAParameterReadFromTheDatabaseIsNotChecked(): void
    {
        $parameter = ProductParameter::stored("Waga\t", '');

        self::assertSame("Waga\t", $parameter->name());
        self::assertSame('', $parameter->value());
    }

    public function testParametersKeepTheOrderAndAllowTheSameValue(): void
    {
        $parameters = ProductParameters::create([
            ProductParameter::of('Waga', '250 g'),
            ProductParameter::of('Masa netto', '250 g'),
            ProductParameter::of('Kolor', 'czarny'),
        ]);

        self::assertSame(['Waga', 'Masa netto', 'Kolor'], array_map(static fn (ProductParameter $p): string => $p->name(), $parameters->toList()));
        self::assertSame(3, $parameters->count());
        self::assertFalse($parameters->isEmpty());
    }

    public function testNoParametersIsAnEmptyList(): void
    {
        self::assertSame([], ProductParameters::create([])->toList());
        self::assertTrue(ProductParameters::create([])->isEmpty());
    }

    public function testParameterNamesDifferWithoutRegardToCaseAndTheEdges(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductParameters must have different names.');

        ProductParameters::create([ProductParameter::of('Waga', '1 kg'), ProductParameter::of("\u{00A0}WAGA\u{200B}", '2 kg')]);
    }

    public function testParameterNamesDifferWithoutRegardToTheCaseOfPolishLetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductParameters::create([ProductParameter::of('Żywotność', '1'), ProductParameter::of('żywotność', '2')]);
    }

    public function testAProductHasAtMost30Parameters(): void
    {
        $thirty = array_map(static fn (int $i): ProductParameter => ProductParameter::of('Parametr '.$i, 'x'), range(1, 30));

        self::assertSame(30, ProductParameters::create($thirty)->count());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductParameters must have at most 30 parameters.');

        ProductParameters::create([...$thirty, ProductParameter::of('Parametr 31', 'x')]);
    }

    public function testStoredParametersAreReadAsTheyAreEvenWhenTheyBreakTheRules(): void
    {
        $parameters = ProductParameters::stored([
            ProductParameter::stored('Waga', '1 kg'),
            ProductParameter::stored('waga', '2 kg'),
        ]);

        self::assertSame(['Waga', 'waga'], array_map(static fn (ProductParameter $p): string => $p->name(), $parameters->toList()));
    }
}
