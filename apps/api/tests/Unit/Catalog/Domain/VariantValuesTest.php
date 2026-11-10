<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\InvalidVariantValues;
use App\Catalog\Domain\Product\VariantValues;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\TestCase;

final class VariantValuesTest extends TestCase
{
    public function testValuesAreTrimmedAndKeepTheirOrder(): void
    {
        $values = VariantValues::fromList([' czarny ', "42\u{00A0}"]);

        self::assertSame(['czarny', '42'], $values->toList());
        self::assertSame(2, $values->count());
    }

    public function testNoValuesIsAccepted(): void
    {
        self::assertSame([], VariantValues::fromList([])->toList());
    }

    public function testValueOf40CharactersIsAcceptedAndOf41IsRejected(): void
    {
        self::assertSame(40, mb_strlen(VariantValues::fromList([str_repeat('ł', 40)])->toList()[0]));

        $this->expectException(InvalidVariantValues::class);
        VariantValues::fromList([str_repeat('ł', 41)]);
    }

    public function testEmptyAndBlankValuesAreRejectedAsInvalidInput(): void
    {
        foreach ([[''], ['  '], ['a', "\t"]] as $list) {
            try {
                VariantValues::fromList($list);
                self::fail('Expected InvalidVariantValues.');
            } catch (InvalidVariantValues $e) {
                self::assertContains(InvalidInput::class, class_implements($e));
            }
        }
    }

    public function testComparisonKeyIgnoresCaseAlsoForPolishLetters(): void
    {
        self::assertSame(
            VariantValues::fromList(['Żółty', 'XL'])->comparisonKey(),
            VariantValues::fromList(['żółty', 'xl'])->comparisonKey(),
        );
        self::assertNotSame(
            VariantValues::fromList(['a', 'b c'])->comparisonKey(),
            VariantValues::fromList(['a b', 'c'])->comparisonKey(),
        );
    }

    public function testLabelJoinsValuesWithCommaAndSpace(): void
    {
        self::assertSame('czarne, 42', VariantValues::fromList(['czarne', '42'])->label());
        self::assertSame('XL', VariantValues::fromList(['XL'])->label());
        self::assertSame('', VariantValues::fromList([])->label());
    }
}
