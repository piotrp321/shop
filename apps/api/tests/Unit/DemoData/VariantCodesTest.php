<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\Catalog\Domain\Product\Ean;
use App\Catalog\Domain\Product\Sku;
use App\DemoData\Catalog\VariantCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SKU and the EAN the generator gives a variant: functions of the index of the product and the position of the
 * variant only (docs/features/product-data/spec.md, "Dane demo" → "Generator").
 */
final class VariantCodesTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function skus(): iterable
    {
        yield 'the first variant of the first product' => [0, 0, '00000001-1'];
        yield 'the third variant of a product in the middle' => [1_234, 2, '00001235-3'];
        yield 'the last product of the profile server, the eighth variant' => [9_999_999, 7, '10000000-8'];
        yield 'a number of nine digits' => [123_456_788, 0, '123456789-1'];
    }

    #[DataProvider('skus')]
    public function testTheSkuIsTheNumberOfTheProductPaddedWithZerosAndTheNumberOfTheVariant(int $index, int $position, string $expected): void
    {
        self::assertSame($expected, VariantCodes::sku($index, $position));
    }

    public function testTheSkuStartsWithADigitSoItNeverMeetsTheSkuOfTheSeedAndPassesTheRuleOfTheCatalogUnchanged(): void
    {
        foreach ([0, 1, 49, 1_999, 9_999_999, 99_999_999] as $index) {
            for ($position = 0; $position < 8; ++$position) {
                $sku = VariantCodes::sku($index, $position);

                self::assertMatchesRegularExpression('/^[0-9]/', $sku);
                self::assertLessThanOrEqual(Sku::MAX_LENGTH, \strlen($sku));
                self::assertSame($sku, Sku::optional($sku)?->value(), $sku);
            }
        }
    }

    public function testNoTwoVariantsHaveTheSameSkuOrTheSameEan(): void
    {
        $skus = [];
        $eans = [];
        for ($index = 0; $index < 3_000; ++$index) {
            for ($position = 0; $position < 8; ++$position) {
                $skus[] = VariantCodes::sku($index, $position);
                $eans[] = VariantCodes::ean($index, $position);
            }
        }

        self::assertCount(24_000, array_unique($skus));
        self::assertCount(24_000, array_unique($eans));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function eans(): iterable
    {
        // 2 + the number of the product (9 digits) + the number of the variant (2 digits) + the check digit of GS1.
        yield 'the first variant of the first product' => [0, 0, '2000000001012'];
    }

    #[DataProvider('eans')]
    public function testTheEanIsThePrefix2TheNumberOfTheProductTheNumberOfTheVariantAndTheCheckDigit(int $index, int $position, string $expected): void
    {
        self::assertSame($expected, VariantCodes::ean($index, $position));
    }

    public function testTheEanIsAnEan13WithAValidCheckDigitOfTheRestrictedCirculationAndPassesTheRuleOfTheCatalogUnchanged(): void
    {
        foreach ([0, 1, 7, 49, 1_999, 123_456, 9_999_999, 999_999_998] as $index) {
            for ($position = 0; $position < 8; ++$position) {
                $ean = VariantCodes::ean($index, $position);
                $checked = Ean::optional($ean);

                self::assertSame(13, \strlen($ean), $ean);
                self::assertStringStartsWith('2', $ean);
                self::assertNotNull($checked, $ean);
                self::assertSame($ean, $checked->value());
                // A number of restricted circulation identifies nothing outside the shop, so the store shows no `gtin` for it.
                self::assertSame('', $checked->gtin(), $ean);
            }
        }
    }

    public function testAProductNumberOrVariantPositionThatDoesNotFitTheEanIsRefusedInsteadOfWritingAnInvalidCode(): void
    {
        // The number of the product has 9 digits (up to 999 999 999), the number of the variant 2 (up to 99).
        self::assertSame(13, \strlen(VariantCodes::ean(999_999_998, 98)));

        foreach ([[999_999_999, 0], [0, 99], [-1, 0], [0, -1]] as [$index, $position]) {
            try {
                VariantCodes::ean($index, $position);
                self::fail(\sprintf('The EAN of product %d, variant %d was written.', $index, $position));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
