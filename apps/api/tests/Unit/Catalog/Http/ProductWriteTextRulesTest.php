<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Http;

use App\Catalog\UI\Http\CreateProductRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * The validator of the product writes applies the rule of texts (App\Shared\Domain\Text; spec api-input-contract,
 * T1–T4) to the name, the short description, the alt texts, the names of the axes and the values of the variants,
 * with one violation at the field.
 */
final class ProductWriteTextRulesTest extends TestCase
{
    private const string KEY = 'originals/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg';

    /**
     * @return array<string, array{string, string, string}> by the field: its path, the message of a text without a
     *                                                      visible character and the message of a forbidden character
     */
    private static function fields(): array
    {
        return [
            'name' => ['name', 'Podaj nazwę produktu.', 'Nazwa produktu zawiera niedozwolone znaki.'],
            'alt' => ['images[0].alt', 'Podaj tekst alternatywny zdjęcia.', 'Tekst alternatywny zawiera niedozwolone znaki.'],
            'axis' => ['variantAxes[0]', 'Nazwa osi musi mieć od 1 do 30 znaków.', 'Nazwa osi zawiera niedozwolone znaki.'],
            'value' => ['variants[0].values[0]', 'Wartość musi mieć od 1 do 40 znaków.', 'Wartość wariantu zawiera niedozwolone znaki.'],
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textsWithoutAVisibleCharacter(): iterable
    {
        foreach (array_keys(self::fields()) as $field) {
            foreach (['U+200B' => "\u{200B}", 'U+3164' => "\u{3164}", 'U+FEFF and U+200E' => "\u{FEFF}\u{200E}"] as $label => $text) {
                yield "$field of $label" => [$field, $text];
            }
        }
    }

    #[DataProvider('textsWithoutAVisibleCharacter')]
    public function testATextWithoutAVisibleCharacterIsAMissingText(string $field, string $text): void
    {
        [$path, $missing] = self::fields()[$field];

        self::assertSame([[$path, $missing]], $this->violations(self::with($field, $text)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenTexts(): iterable
    {
        foreach (array_keys(self::fields()) as $field) {
            foreach (['U+009F inside' => "Bu\u{9F}ty", 'U+0080 inside' => "Bu\u{80}ty", 'U+2028 at the end' => "Buty\u{2028}"] as $label => $text) {
                yield "$field with $label" => [$field, $text];
            }
        }
    }

    #[DataProvider('forbiddenTexts')]
    public function testAForbiddenCharacterIsRefusedAtItsField(string $field, string $text): void
    {
        [$path, , $forbidden] = self::fields()[$field];

        self::assertSame([[$path, $forbidden]], $this->violations(self::with($field, $text)));
    }

    public function testTheShortDescriptionRefusesAC1ControlCharacterAndTakesALineFeed(): void
    {
        self::assertSame([['shortDescription', 'Krótki opis zawiera niedozwolone znaki.']], $this->violations(['shortDescription' => "Lek\u{9F}kie"]));
        self::assertSame([], $this->violations(['shortDescription' => "Lekkie\nbuty"]));
        self::assertSame([], $this->violations(['shortDescription' => "\u{200B}"]));
    }

    public function testTheLimitsAreCountedAfterTheTrimOfInvisibleCharacters(): void
    {
        self::assertSame([], $this->violations([
            'name' => "\u{200B}".str_repeat('a', 200)."\u{FEFF}",
            'shortDescription' => "\u{200B}".str_repeat('a', 500)."\u{200B}",
            'images' => [['key' => self::KEY, 'alt' => "\u{2060}".str_repeat('a', 200)."\u{2060}"]],
            'variantAxes' => ["\u{AD}".str_repeat('a', 30)."\u{AD}"],
            'variants' => [['variantId' => null, 'values' => ["\u{200B}".str_repeat('a', 40)."\u{200B}"]]],
        ]));
    }

    public function testTheNamesOfAxesAndTheValuesOfVariantsAreComparedAfterTheTrim(): void
    {
        self::assertSame([['variantAxes[1]', 'Nazwa osi już jest użyta.']], $this->violations([
            'variantAxes' => ['Kolor', "\u{200B}kolor"],
            'variants' => [['variantId' => null, 'values' => ['M', 'czarny']]],
        ]));
        self::assertSame([['variants[1].values', 'Taki wariant już istnieje.']], $this->violations([
            'variants' => [['variantId' => null, 'values' => ['M']], ['variantId' => null, 'values' => ["\u{200B}m"]]],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function with(string $field, string $text): array
    {
        return match ($field) {
            'name' => ['name' => $text],
            'alt' => ['images' => [['key' => self::KEY, 'alt' => $text]]],
            'axis' => ['variantAxes' => [$text]],
            'value' => ['variants' => [['variantId' => null, 'values' => [$text]], ['variantId' => null, 'values' => ['L']]]],
            default => throw new \LogicException($field),
        };
    }

    /**
     * @param array<string, mixed> $changes of a valid body
     *
     * @return list<array{string, string}> path and message of each violation
     */
    private function violations(array $changes): array
    {
        $body = [
            'name' => 'Buty biegowe',
            'slug' => 'buty-biegowe',
            'shortDescription' => 'Lekkie buty na szlak.',
            'mainCategoryId' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60',
            'additionalCategoryIds' => [],
            'taxClass' => 'standard',
            'images' => [['key' => self::KEY, 'alt' => 'Buty z boku']],
            'variantAxes' => ['Rozmiar'],
            'variants' => [['variantId' => null, 'values' => ['M']], ['variantId' => null, 'values' => ['L']]],
            ...$changes,
        ];
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = [];
        foreach ($validator->validate(new CreateProductRequest(...$body)) as $violation) {
            $violations[] = [$violation->getPropertyPath(), (string) $violation->getMessage()];
        }

        return $violations;
    }
}
