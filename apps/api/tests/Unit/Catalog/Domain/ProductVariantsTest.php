<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\DuplicateVariantValues;
use App\Catalog\Domain\Product\InvalidProductVariants;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\TestCase;

final class ProductVariantsTest extends TestCase
{
    public function testProductWithoutAxesHasExactlyOneDefaultVariantWithoutValues(): void
    {
        $variants = ProductVariants::create(VariantAxes::fromList([]), [self::variant(1, [])]);

        self::assertSame(1, $variants->count());
        self::assertSame([], $variants->axes()->names());
        self::assertSame('', $variants->toList()[0]->label());
        self::assertSame(self::id(1), $variants->toList()[0]->id()->toString());
    }

    public function testNoVariantsIsRejected(): void
    {
        $this->assertInvalid(['Kolor'], []);
        $this->assertInvalid([], []);
    }

    public function testWithoutAxesMoreThanOneVariantIsRejected(): void
    {
        $this->assertInvalid([], [self::variant(1, []), self::variant(2, [])]);
    }

    public function testVariantWithoutAxesCannotHaveValues(): void
    {
        $this->assertInvalid([], [self::variant(1, ['czarny'])]);
    }

    public function testNumberOfValuesMustEqualNumberOfAxes(): void
    {
        $this->assertInvalid(['Kolor', 'Rozmiar'], [self::variant(1, ['czarny'])]);
        $this->assertInvalid(['Kolor'], [self::variant(1, ['czarny', '42'])]);
        $this->assertInvalid(['Kolor'], [self::variant(1, [])]);
    }

    public function testVariantsKeepTheirOrderForOneAndTwoAxes(): void
    {
        $one = ProductVariants::create(VariantAxes::fromList(['Rozmiar']), [self::variant(1, ['S']), self::variant(2, ['M'])]);
        $two = ProductVariants::create(VariantAxes::fromList(['Kolor', 'Rozmiar']), [
            self::variant(3, ['czarny', 'S']),
            self::variant(4, ['czarny', 'M']),
            self::variant(5, ['biały', 'S']),
        ]);

        self::assertSame(['S', 'M'], array_map(static fn (ProductVariant $v): string => $v->label(), $one->toList()));
        self::assertSame(['czarny, S', 'czarny, M', 'biały, S'], array_map(static fn (ProductVariant $v): string => $v->label(), $two->toList()));
    }

    public function testAHundredVariantsAreAcceptedAndOneHundredAndOneRejected(): void
    {
        $hundred = array_map(static fn (int $i): ProductVariant => self::variant($i, ['v'.$i]), range(1, 100));
        self::assertSame(100, ProductVariants::create(VariantAxes::fromList(['Wersja']), $hundred)->count());

        $this->assertInvalid(['Wersja'], [...$hundred, self::variant(101, ['v101'])]);
    }

    public function testRepeatedVariantIdIsRejected(): void
    {
        $this->assertInvalid(['Kolor'], [self::variant(1, ['czarny']), self::variant(1, ['biały'])]);
    }

    public function testRepeatedCombinationIsRejected(): void
    {
        $this->assertDuplicate(['Kolor'], [self::variant(1, ['czarny']), self::variant(2, ['czarny'])]);
        $this->assertDuplicate(['Kolor', 'Rozmiar'], [self::variant(1, ['czarny', '42']), self::variant(2, ['czarny', '42'])]);
    }

    public function testRepeatedCombinationAfterTrimmingIsRejected(): void
    {
        $this->assertDuplicate(['Kolor'], [self::variant(1, ['czarny']), self::variant(2, ['  czarny '])]);
    }

    public function testRepeatedCombinationWithAnotherCaseIsRejected(): void
    {
        $this->assertDuplicate(['Kolor'], [self::variant(1, ['Czarny']), self::variant(2, ['czarny'])]);
        $this->assertDuplicate(['Rozmiar'], [self::variant(1, ['XL']), self::variant(2, ['xl'])]);
    }

    public function testRepeatedCombinationWithPolishLettersOfAnotherCaseIsRejected(): void
    {
        $this->assertDuplicate(['Kolor'], [self::variant(1, ['Żółty']), self::variant(2, ['żółty'])]);
        $this->assertDuplicate(['Kolor'], [self::variant(1, ['ŚWIT']), self::variant(2, ['świt'])]);
    }

    public function testCombinationsDifferingInOneValueAreAccepted(): void
    {
        $variants = ProductVariants::create(VariantAxes::fromList(['Kolor', 'Rozmiar']), [
            self::variant(1, ['czarny', '42']),
            self::variant(2, ['czarny', '43']),
            self::variant(3, ['biały', '42']),
        ]);

        self::assertSame(3, $variants->count());
    }

    public function testValuesThatShareAConcatenationAreDifferentCombinations(): void
    {
        $variants = ProductVariants::create(VariantAxes::fromList(['A', 'B']), [
            self::variant(1, ['a', 'b c']),
            self::variant(2, ['a b', 'c']),
        ]);

        self::assertSame(2, $variants->count());
    }

    public function testVariantLabelOfTheDefaultVariantIsEmpty(): void
    {
        self::assertSame('', self::variant(1, [])->label());
        self::assertSame('czarne, 42', self::variant(1, ['czarne', '42'])->label());
    }

    /**
     * @param list<string>         $axes
     * @param list<ProductVariant> $variants
     */
    private function assertInvalid(array $axes, array $variants): void
    {
        try {
            ProductVariants::create(VariantAxes::fromList($axes), $variants);
            self::fail('Expected InvalidProductVariants.');
        } catch (InvalidProductVariants $e) {
            self::assertContains(InvalidInput::class, class_implements($e));
        }
    }

    /**
     * @param list<string>         $axes
     * @param list<ProductVariant> $variants
     */
    private function assertDuplicate(array $axes, array $variants): void
    {
        try {
            ProductVariants::create(VariantAxes::fromList($axes), $variants);
            self::fail('Expected DuplicateVariantValues.');
        } catch (DuplicateVariantValues $e) {
            self::assertContains(InvalidInput::class, class_implements($e));
        }
    }

    /**
     * @param list<string> $values
     */
    private static function variant(int $number, array $values): ProductVariant
    {
        return new ProductVariant(VariantId::fromString(self::id($number)), VariantValues::fromList($values));
    }

    private static function id(int $number): string
    {
        return \sprintf('a0000000-0000-4000-8000-%012d', $number);
    }
}
