<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\TaxClassCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxClassCodeTest extends TestCase
{
    #[DataProvider('knownValues')]
    public function testKnownValueIsAccepted(string $value, TaxClassCode $expected): void
    {
        self::assertSame($expected, TaxClassCode::from($value));
    }

    /** @return iterable<string, array{string, TaxClassCode}> */
    public static function knownValues(): iterable
    {
        yield 'standard' => ['standard', TaxClassCode::Standard];
        yield 'printed_book' => ['printed_book', TaxClassCode::PrintedBook];
        yield 'printed_map' => ['printed_map', TaxClassCode::PrintedMap];
        yield 'freeze_dried_meal' => ['freeze_dried_meal', TaxClassCode::FreezeDriedMeal];
        yield 'energy_bar' => ['energy_bar', TaxClassCode::EnergyBar];
        yield 'energy_gel' => ['energy_gel', TaxClassCode::EnergyGel];
        yield 'protein_powder' => ['protein_powder', TaxClassCode::ProteinPowder];
        yield 'electrolyte_tablet' => ['electrolyte_tablet', TaxClassCode::ElectrolyteTablet];
    }

    public function testHasExactlyTheEightClasses(): void
    {
        self::assertCount(8, [...self::knownValues()]);
        self::assertCount(\count([...self::knownValues()]), TaxClassCode::cases());
    }

    /** @return iterable<string, array{string}> */
    public static function removedOrUnknownValues(): iterable
    {
        yield 'unknown' => ['luxury'];
        yield 'old food category' => ['food'];
        yield 'old food supplement category' => ['food_supplement'];
        yield 'old printed publication category' => ['printed_publication'];
    }

    #[DataProvider('removedOrUnknownValues')]
    public function testUnknownValueThrows(string $value): void
    {
        $this->expectException(\ValueError::class);

        TaxClassCode::from($value);
    }
}
