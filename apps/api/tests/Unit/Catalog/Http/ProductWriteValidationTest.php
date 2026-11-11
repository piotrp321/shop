<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Http;

use App\Catalog\UI\Http\CreateProductRequest;
use App\Catalog\UI\Http\UpdateProductRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The body of a product write: every rule of the spec ("API panelu") with the path of the field and a Polish message.
 */
final class ProductWriteValidationTest extends TestCase
{
    private const string UUID_A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01';
    private const string UUID_B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02';
    private const string UUID_C = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /**
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        return [
            'name' => 'Buty biegowe',
            'slug' => 'buty-biegowe',
            'shortDescription' => 'Lekkie',
            'mainCategoryId' => self::UUID_A,
            'additionalCategoryIds' => [self::UUID_B],
            'taxClass' => 'standard',
            'images' => [['key' => 'originals/'.str_repeat('a', 64).'.jpg', 'alt' => 'Przód']],
            'variantAxes' => ['Kolor', 'Rozmiar'],
            'variants' => [
                ['variantId' => null, 'values' => ['czarne', '40']],
                ['variantId' => self::UUID_C, 'values' => ['czarne', '41']],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function with(array $changes): array
    {
        return [...self::valid(), ...$changes];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function violations(): iterable
    {
        $key = 'originals/'.str_repeat('a', 64).'.jpg';
        $other = 'originals/'.str_repeat('b', 64).'.png';

        yield 'a valid body' => [self::valid(), []];
        yield 'an empty slug, no categories, images, axes' => [self::with(['slug' => '', 'additionalCategoryIds' => [], 'images' => [], 'variantAxes' => [], 'variants' => [['variantId' => null, 'values' => []]]]), []];
        yield 'limits at the edge' => [self::with([
            'name' => str_repeat('ż', 200), 'slug' => str_repeat('a', 200), 'shortDescription' => str_repeat('ż', 500),
            'additionalCategoryIds' => array_map(static fn (int $n): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n), range(1, 10)),
            'images' => array_map(static fn (int $n): array => ['key' => 'originals/'.str_pad(dechex($n), 64, '0', \STR_PAD_LEFT).'.jpg', 'alt' => str_repeat('ż', 200)], range(1, 9)),
            'variantAxes' => [str_repeat('ż', 30), 'B'],
            'variants' => array_map(static fn (int $n): array => ['variantId' => null, 'values' => [str_repeat('ż', 40), (string) $n]], range(1, 100)),
        ]), []];

        yield 'a missing name' => [self::with(['name' => null]), ['name' => 'Podaj nazwę produktu.']];
        yield 'a blank name' => [self::with(['name' => " \u{00A0} "]), ['name' => 'Podaj nazwę produktu.']];
        yield 'a name that is no text' => [self::with(['name' => 7]), ['name' => 'Podaj nazwę produktu.']];
        yield 'a name over 200 characters' => [self::with(['name' => str_repeat('a', 201)]), ['name' => 'Nazwa może mieć najwyżej 200 znaków.']];
        yield 'a slug that is no text' => [self::with(['slug' => null]), ['slug' => 'Slug musi być tekstem.']];
        yield 'a slug over 200 characters' => [self::with(['slug' => str_repeat('a', 201)]), ['slug' => 'Slug może mieć najwyżej 200 znaków.']];
        yield 'a slug in the wrong format' => [self::with(['slug' => 'Buty Biegowe']), ['slug' => 'Slug może zawierać tylko małe litery bez polskich znaków, cyfry i pojedyncze myślniki.']];
        yield 'a slug with a double dash' => [self::with(['slug' => 'buty--biegowe']), ['slug' => 'Slug może zawierać tylko małe litery bez polskich znaków, cyfry i pojedyncze myślniki.']];
        yield 'a short description that is no text' => [self::with(['shortDescription' => ['x']]), ['shortDescription' => 'Krótki opis musi być tekstem.']];
        yield 'a short description over 500 characters' => [self::with(['shortDescription' => str_repeat('a', 501)]), ['shortDescription' => 'Krótki opis może mieć najwyżej 500 znaków.']];

        yield 'a missing main category' => [self::with(['mainCategoryId' => null]), ['mainCategoryId' => 'Wybierz kategorię główną.']];
        yield 'a main category that is no uuid' => [self::with(['mainCategoryId' => 'sport']), ['mainCategoryId' => 'Wybierz kategorię główną.']];
        yield 'additional categories that are no list' => [self::with(['additionalCategoryIds' => 'x']), ['additionalCategoryIds' => 'Kategorie dodatkowe muszą być listą.']];
        yield 'additional categories as an object' => [self::with(['additionalCategoryIds' => ['a' => self::UUID_B]]), ['additionalCategoryIds' => 'Kategorie dodatkowe muszą być listą.']];
        yield 'eleven additional categories' => [self::with(['additionalCategoryIds' => array_map(static fn (int $n): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n), range(1, 11))]), ['additionalCategoryIds' => 'Produkt może mieć najwyżej 10 kategorii dodatkowych.']];
        yield 'an additional category that is no uuid' => [self::with(['additionalCategoryIds' => [self::UUID_B, 5]]), ['additionalCategoryIds[1]' => 'Wybierz poprawną kategorię.']];
        yield 'the main category among the additional ones' => [self::with(['additionalCategoryIds' => [self::UUID_B, strtoupper(self::UUID_A)]]), ['additionalCategoryIds[1]' => 'Kategoria główna nie może być też dodatkowa.']];
        yield 'a repeated additional category' => [self::with(['additionalCategoryIds' => [self::UUID_B, self::UUID_C, strtoupper(self::UUID_B)]]), ['additionalCategoryIds[2]' => 'Ta kategoria już jest wybrana.']];
        yield 'an unknown tax class' => [self::with(['taxClass' => 'luxury']), ['taxClass' => 'Wybierz klasę podatkową.']];
        yield 'a missing tax class' => [self::with(['taxClass' => null]), ['taxClass' => 'Wybierz klasę podatkową.']];

        yield 'images that are no list' => [self::with(['images' => 'x']), ['images' => 'Zdjęcia muszą być listą.']];
        yield 'eleven images' => [self::with(['images' => array_map(static fn (int $n): array => ['key' => 'originals/'.str_pad(dechex($n), 64, '0', \STR_PAD_LEFT).'.jpg', 'alt' => 'x'], range(1, 11))]), ['images' => 'Produkt może mieć najwyżej 10 zdjęć.']];
        yield 'an image that is no object' => [self::with(['images' => ['x']]), ['images[0]' => 'Zdjęcie jest nieprawidłowe.']];
        yield 'an image key in the wrong format' => [self::with(['images' => [['key' => 'originals/abc.jpg', 'alt' => 'x']]]), ['images[0].key' => 'Klucz zdjęcia jest nieprawidłowy.']];
        yield 'a missing image key' => [self::with(['images' => [['alt' => 'x']]]), ['images[0].key' => 'Klucz zdjęcia jest nieprawidłowy.']];
        yield 'a key of a svg' => [self::with(['images' => [['key' => 'originals/'.str_repeat('a', 64).'.svg', 'alt' => 'x']]]), ['images[0].key' => 'Klucz zdjęcia jest nieprawidłowy.']];
        yield 'a repeated image key' => [self::with(['images' => [['key' => $key, 'alt' => 'a'], ['key' => $other, 'alt' => 'b'], ['key' => $key, 'alt' => 'c']]]), ['images[2].key' => 'To zdjęcie już jest w produkcie.']];
        yield 'a blank alt' => [self::with(['images' => [['key' => $key, 'alt' => ' ']]]), ['images[0].alt' => 'Podaj tekst alternatywny zdjęcia.']];
        yield 'an alt that is no text' => [self::with(['images' => [['key' => $key, 'alt' => null]]]), ['images[0].alt' => 'Podaj tekst alternatywny zdjęcia.']];
        yield 'an alt over 200 characters' => [self::with(['images' => [['key' => $key, 'alt' => str_repeat('a', 201)]]]), ['images[0].alt' => 'Tekst alternatywny może mieć najwyżej 200 znaków.']];

        yield 'axes that are no list' => [self::with(['variantAxes' => 'Kolor']), ['variantAxes' => 'Osie wariantów muszą być listą.']];
        yield 'three axes' => [self::with(['variantAxes' => ['A', 'B', 'C'], 'variants' => [['variantId' => null, 'values' => ['1', '2', '3']]]]), ['variantAxes' => 'Produkt może mieć najwyżej 2 osie wariantów.']];
        yield 'an empty axis name' => [self::with(['variantAxes' => ['Kolor', '  '], 'variants' => [['variantId' => null, 'values' => ['a', 'b']]]]), ['variantAxes[1]' => 'Nazwa osi musi mieć od 1 do 30 znaków.']];
        yield 'an axis name over 30 characters' => [self::with(['variantAxes' => [str_repeat('a', 31)], 'variants' => [['variantId' => null, 'values' => ['a']]]]), ['variantAxes[0]' => 'Nazwa osi musi mieć od 1 do 30 znaków.']];
        yield 'an axis name that is no text' => [self::with(['variantAxes' => [3], 'variants' => [['variantId' => null, 'values' => ['a']]]]), ['variantAxes[0]' => 'Nazwa osi musi mieć od 1 do 30 znaków.']];
        yield 'an axis name with a colon' => [self::with(['variantAxes' => ['Kolor: ciemny'], 'variants' => [['variantId' => null, 'values' => ['a']]]]), ['variantAxes[0]' => 'Nazwa osi nie może zawierać dwukropka.']];
        yield 'a repeated axis name' => [self::with(['variantAxes' => ['Kolor', ' kolor ']]), ['variantAxes[1]' => 'Nazwa osi już jest użyta.']];

        yield 'variants that are no list' => [self::with(['variants' => 'x']), ['variants' => 'Warianty muszą być listą.']];
        yield 'no variants' => [self::with(['variants' => []]), ['variants' => 'Produkt musi mieć od 1 do 100 wariantów.']];
        yield 'a hundred and one variants' => [self::with(['variantAxes' => ['A'], 'variants' => array_map(static fn (int $n): array => ['variantId' => null, 'values' => [(string) $n]], range(1, 101))]), ['variants' => 'Produkt musi mieć od 1 do 100 wariantów.']];
        yield 'a variant that is no object' => [self::with(['variants' => [['variantId' => null, 'values' => ['a', 'b']], 'x']]), ['variants[1]' => 'Wariant jest nieprawidłowy.']];
        yield 'a variant id that is no uuid' => [self::with(['variants' => [['variantId' => 'abc', 'values' => ['a', 'b']]]]), ['variants[0].variantId' => 'Identyfikator wariantu jest nieprawidłowy.']];
        yield 'a missing variant id' => [self::with(['variants' => [['values' => ['a', 'b']]]]), ['variants[0].variantId' => 'Identyfikator wariantu jest nieprawidłowy.']];
        yield 'a repeated variant id' => [self::with(['variants' => [['variantId' => self::UUID_C, 'values' => ['a', 'b']], ['variantId' => strtoupper(self::UUID_C), 'values' => ['c', 'd']]]]), ['variants[1].variantId' => 'Ten wariant jest powtórzony.']];
        yield 'values that are no list' => [self::with(['variants' => [['variantId' => null, 'values' => 'a']]]), ['variants[0].values' => 'Wartości wariantu muszą być listą.']];
        yield 'fewer values than axes' => [self::with(['variants' => [['variantId' => null, 'values' => ['a']]]]), ['variants[0].values' => 'Wariant musi mieć jedną wartość dla każdej osi.']];
        yield 'values for a product without axes' => [self::with(['variantAxes' => [], 'variants' => [['variantId' => null, 'values' => ['a']]]]), ['variants[0].values' => 'Wariant musi mieć jedną wartość dla każdej osi.']];
        yield 'an empty value' => [self::with(['variants' => [['variantId' => null, 'values' => ['a', 'b']], ['variantId' => null, 'values' => ['a', " \u{00A0}"]]]]), ['variants[1].values[1]' => 'Wartość musi mieć od 1 do 40 znaków.']];
        yield 'a value over 40 characters' => [self::with(['variants' => [['variantId' => null, 'values' => [str_repeat('a', 41), 'b']]]]), ['variants[0].values[0]' => 'Wartość musi mieć od 1 do 40 znaków.']];
        yield 'a value that is no text' => [self::with(['variants' => [['variantId' => null, 'values' => ['a', 4]]]]), ['variants[0].values[1]' => 'Wartość musi mieć od 1 do 40 znaków.']];
        yield 'a repeated combination' => [self::with(['variants' => [['variantId' => null, 'values' => ['czarne', '40']], ['variantId' => null, 'values' => ['Czarne ', '41']], ['variantId' => null, 'values' => [' CZARNE', '40 ']]]]), ['variants[2].values' => 'Taki wariant już istnieje.']];
        yield 'the same combination written in Polish capitals' => [self::with(['variantAxes' => ['Kolor'], 'variants' => [['variantId' => null, 'values' => ['żółte']], ['variantId' => null, 'values' => ['ŻÓŁTE']]]]), ['variants[1].values' => 'Taki wariant już istnieje.']];
        yield 'more than one row without axes' => [self::with(['variantAxes' => [], 'variants' => [['variantId' => null, 'values' => []], ['variantId' => null, 'values' => []]]]), ['variants' => 'Produkt bez osi ma tylko jeden wariant.']];

        // Forbidden characters and Unicode whitespace (#247); an invalid UTF-8 byte cannot arrive in a JSON body (the
        // decoder answers 400 first), so only this test shows how the validator treats it.
        $forbidden = ['NUL' => "a\x00b", 'BEL' => "a\x07b", 'line separator' => "a\u{2028}b", 'invalid UTF-8' => "a\xC3\x28b", 'tab at the end' => "ab\t"];
        foreach ($forbidden as $label => $text) {
            yield "a name with $label" => [self::with(['name' => $text]), ['name' => 'Nazwa produktu zawiera niedozwolone znaki.']];
            yield "a short description with $label" => [self::with(['shortDescription' => $text]), ['shortDescription' => 'Krótki opis zawiera niedozwolone znaki.']];
            yield "an alt with $label" => [self::with(['images' => [['key' => $key, 'alt' => $text]]]), ['images[0].alt' => 'Tekst alternatywny zawiera niedozwolone znaki.']];
            yield "an axis name with $label" => [self::with(['variantAxes' => [$text], 'variants' => [['variantId' => null, 'values' => ['a']]]]), ['variantAxes[0]' => 'Nazwa osi zawiera niedozwolone znaki.']];
            yield "a value with $label" => [self::with(['variants' => [['variantId' => null, 'values' => ['a', $text]]]]), ['variants[0].values[1]' => 'Wartość wariantu zawiera niedozwolone znaki.']];
        }
        yield 'a short description with a line feed' => [self::with(['shortDescription' => "Linia 1\nLinia 2"]), []];
        yield 'a short description of only whitespace' => [self::with(['shortDescription' => "\u{00A0}\n\u{3000}"]), []];
        yield 'a name with a line feed' => [self::with(['name' => "a\nb"]), ['name' => 'Nazwa produktu zawiera niedozwolone znaki.']];
        yield 'an alt with a line feed' => [self::with(['images' => [['key' => $key, 'alt' => "a\nb"]]]), ['images[0].alt' => 'Tekst alternatywny zawiera niedozwolone znaki.']];
        yield 'an axis name with a line feed' => [self::with(['variantAxes' => ["a\nb"], 'variants' => [['variantId' => null, 'values' => ['a']]]]), ['variantAxes[0]' => 'Nazwa osi zawiera niedozwolone znaki.']];
        yield 'a value with a line feed' => [self::with(['variants' => [['variantId' => null, 'values' => ['a', "a\nb"]]]]), ['variants[0].values[1]' => 'Wartość wariantu zawiera niedozwolone znaki.']];
        yield 'a name of only non-breaking spaces' => [self::with(['name' => "\u{00A0}\u{00A0}"]), ['name' => 'Podaj nazwę produktu.']];
        yield 'an alt of only ideographic spaces' => [self::with(['images' => [['key' => $key, 'alt' => "\u{3000}"]]]), ['images[0].alt' => 'Podaj tekst alternatywny zdjęcia.']];
        yield 'an axis name of only non-breaking spaces' => [self::with(['variantAxes' => ["\u{00A0}"], 'variants' => [['variantId' => null, 'values' => ['a']]]]), ['variantAxes[0]' => 'Nazwa osi musi mieć od 1 do 30 znaków.']];
        yield 'a value of only non-breaking spaces' => [self::with(['variants' => [['variantId' => null, 'values' => ['a', "\u{00A0}"]]]]), ['variants[0].values[1]' => 'Wartość musi mieć od 1 do 40 znaków.']];
        yield 'a name of 200 characters with non-breaking spaces around' => [self::with(['name' => "\u{00A0}".str_repeat('ż', 200)."\u{00A0}"]), []];
        yield 'a repeated combination with non-breaking spaces' => [self::with(['variantAxes' => ['Kolor'], 'variants' => [['variantId' => null, 'values' => ['czarne']], ['variantId' => null, 'values' => ["\u{00A0}Czarne\u{00A0}"]]]]), ['variants[1].values' => 'Taki wariant już istnieje.']];
        yield 'a repeated axis name with non-breaking spaces' => [self::with(['variantAxes' => ['Kolor', "\u{00A0}kolor"]]), ['variantAxes[1]' => 'Nazwa osi już jest użyta.']];

        yield 'several mistakes at once, in the order of the body' => [
            self::with(['name' => '', 'taxClass' => 'x', 'images' => [['key' => 'k', 'alt' => '']], 'variants' => [['variantId' => 'z', 'values' => []]]]),
            [
                'name' => 'Podaj nazwę produktu.',
                'taxClass' => 'Wybierz klasę podatkową.',
                'images[0].key' => 'Klucz zdjęcia jest nieprawidłowy.',
                'images[0].alt' => 'Podaj tekst alternatywny zdjęcia.',
                'variants[0].variantId' => 'Identyfikator wariantu jest nieprawidłowy.',
                'variants[0].values' => 'Wariant musi mieć jedną wartość dla każdej osi.',
            ],
        ];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $expected
     */
    #[DataProvider('violations')]
    public function testCreateBodyViolationsHavePathsAndPolishMessages(array $body, array $expected): void
    {
        self::assertSame($expected, $this->report(new CreateProductRequest(...$body)));
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $expected
     */
    #[DataProvider('violations')]
    public function testUpdateBodyHasTheSameRules(array $body, array $expected): void
    {
        self::assertSame($expected, $this->report(new UpdateProductRequest(...[...$body, 'version' => 3])));
    }

    /**
     * @return iterable<string, array{mixed, array<string, string>}>
     */
    public static function versions(): iterable
    {
        yield 'a version' => [1, []];
        yield 'a large version' => [9_007_199_254_740_991, []];
        yield 'a missing version' => [null, ['version' => 'Podaj wersję produktu.']];
        yield 'zero' => [0, ['version' => 'Podaj wersję produktu.']];
        yield 'a negative version' => [-1, ['version' => 'Podaj wersję produktu.']];
        yield 'a version in a string' => ['2', ['version' => 'Podaj wersję produktu.']];
        yield 'a fractional version' => [1.5, ['version' => 'Podaj wersję produktu.']];
        yield 'a whole number as a float' => [2.0, ['version' => 'Podaj wersję produktu.']];
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('versions')]
    public function testUpdateBodyNeedsTheVersion(mixed $version, array $expected): void
    {
        self::assertSame($expected, $this->report(new UpdateProductRequest(...[...self::valid(), 'version' => $version])));
    }

    public function testMessagesNeverEchoTheSubmittedValue(): void
    {
        $body = self::with(['name' => str_repeat('Ł', 201), 'slug' => 'ZŁY SLUG', 'taxClass' => 'tajna', 'mainCategoryId' => 'sekret']);

        $messages = implode(' ', $this->report(new CreateProductRequest(...$body)));

        foreach (['ZŁY', 'tajna', 'sekret', 'ŁŁŁ'] as $secret) {
            self::assertStringNotContainsString($secret, $messages);
        }
    }

    /**
     * @return array<string, string> the first message per path, in the order the violations came
     */
    private function report(object $request): array
    {
        $report = [];
        foreach ($this->validator->validate($request) as $violation) {
            $report[$violation->getPropertyPath()] ??= (string) $violation->getMessage();
        }

        return $report;
    }
}
