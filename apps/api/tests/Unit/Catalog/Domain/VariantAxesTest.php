<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\InvalidVariantAxes;
use App\Catalog\Domain\Product\VariantAxes;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariantAxesTest extends TestCase
{
    public function testNoAxesIsAccepted(): void
    {
        $axes = VariantAxes::fromList([]);

        self::assertSame([], $axes->names());
        self::assertSame(0, $axes->count());
    }

    public function testOneAndTwoAxesKeepTheirOrder(): void
    {
        self::assertSame(['Kolor'], VariantAxes::fromList(['Kolor'])->names());
        self::assertSame(['Kolor', 'Rozmiar'], VariantAxes::fromList(['Kolor', 'Rozmiar'])->names());
        self::assertSame(2, VariantAxes::fromList(['Kolor', 'Rozmiar'])->count());
    }

    public function testNamesAreTrimmed(): void
    {
        self::assertSame(['Kolor', 'Rozmiar'], VariantAxes::fromList(['  Kolor ', "\u{00A0}Rozmiar\u{3000}"])->names());
    }

    public function testNameOf30CharactersIsAcceptedAndOf31IsRejected(): void
    {
        self::assertSame(30, mb_strlen(VariantAxes::fromList([str_repeat('ż', 30)])->names()[0]));

        $this->expectException(InvalidVariantAxes::class);
        VariantAxes::fromList([str_repeat('ż', 31)]);
    }

    public function testLengthIsMeasuredAfterTrimming(): void
    {
        self::assertSame(str_repeat('a', 30), VariantAxes::fromList(['  '.str_repeat('a', 30).'  '])->names()[0]);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidAxes(): iterable
    {
        yield 'empty name' => [['']];
        yield 'blank name' => [['   ']];
        yield 'three axes' => [['A', 'B', 'C']];
        yield 'same name twice' => [['Kolor', 'Kolor']];
        yield 'same name with another case' => [['Kolor', 'KOLOR']];
        yield 'same name after trimming' => [['Kolor', ' Kolor ']];
        yield 'same Polish name with another case' => [['Żółw', 'żółw']];
        yield 'name with a colon' => [['Kolor:']];
        yield 'colon inside the second name' => [['Kolor', 'Roz:miar']];
    }

    public function testAThirdAxisIsRejectedWhateverTheNamesAre(): void
    {
        // The search model counts a variant with at most two options (spec search, M1).
        foreach ([['A', 'B', 'C'], ['Kolor', 'Rozmiar', 'Materiał'], ['a', 'b', 'c', 'd']] as $names) {
            try {
                VariantAxes::fromList($names);
                self::fail('Expected InvalidVariantAxes.');
            } catch (InvalidVariantAxes $e) {
                self::assertSame('A product has at most 2 variant axes.', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesWithASeparatorOfTheSearchIndex(): iterable
    {
        yield 'the pair separator U+001E inside' => ["Ko\u{1E}lor"];
        yield 'the option separator U+001F inside' => ["Ko\u{1F}lor"];
        yield 'the pair separator at the end' => ["Kolor\u{1E}"];
        yield 'the option separator at the end' => ["Kolor\u{1F}"];
        yield 'the option separator at the start' => ["\u{1F}Kolor"];
    }

    #[DataProvider('namesWithASeparatorOfTheSearchIndex')]
    public function testASeparatorOfTheSearchIndexInANameIsRejected(string $name): void
    {
        $this->expectExceptionObject(InvalidVariantAxes::nameWithForbiddenCharacters());

        VariantAxes::fromList(['Rozmiar', $name]);
    }

    public function testColonInANameIsRejectedWithItsOwnMessage(): void
    {
        $this->expectException(InvalidVariantAxes::class);
        $this->expectExceptionMessage('The name of a variant axis must not contain a colon.');

        VariantAxes::fromList([':']);
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('invalidAxes')]
    public function testInvalidAxesAreRejectedAsInvalidInput(array $names): void
    {
        try {
            VariantAxes::fromList($names);
            self::fail('Expected InvalidVariantAxes.');
        } catch (InvalidVariantAxes $e) {
            self::assertContains(InvalidInput::class, class_implements($e));
            self::assertStringNotContainsString('Kolor', $e->getMessage());
        }
    }
}
