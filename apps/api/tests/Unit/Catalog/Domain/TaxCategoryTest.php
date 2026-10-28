<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\TaxCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxCategoryTest extends TestCase
{
    #[DataProvider('knownValues')]
    public function testKnownValueIsAccepted(string $value, TaxCategory $expected): void
    {
        self::assertSame($expected, TaxCategory::from($value));
    }

    /** @return iterable<string, array{string, TaxCategory}> */
    public static function knownValues(): iterable
    {
        yield 'standard' => ['standard', TaxCategory::Standard];
        yield 'food' => ['food', TaxCategory::Food];
        yield 'food_supplement' => ['food_supplement', TaxCategory::FoodSupplement];
        yield 'printed_publication' => ['printed_publication', TaxCategory::PrintedPublication];
    }

    public function testUnknownValueThrows(): void
    {
        $this->expectException(\ValueError::class);

        TaxCategory::from('luxury');
    }
}
