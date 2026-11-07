<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Query;

use App\Catalog\Application\Query\ListTaxClasses;
use App\Catalog\Application\Query\ListTaxClassesHandler;
use App\Catalog\Application\Query\TaxClassView;
use App\Catalog\Domain\Product\TaxClassCode;
use PHPUnit\Framework\TestCase;

final class ListTaxClassesHandlerTest extends TestCase
{
    public function testEveryTaxClassOfTheCatalogIsListedWithAPolishNameInTheOrderOfTheEnum(): void
    {
        $classes = (new ListTaxClassesHandler())(new ListTaxClasses());

        self::assertSame(
            array_map(static fn (TaxClassCode $code): string => $code->value, TaxClassCode::cases()),
            array_map(static fn (TaxClassView $class): string => $class->code, $classes),
        );
        foreach ($classes as $class) {
            self::assertNotSame('', $class->name);
            self::assertNotSame($class->code, $class->name);
        }
    }

    public function testNamesAreStable(): void
    {
        $names = array_column(array_map(static fn (TaxClassView $class): array => ['code' => $class->code, 'name' => $class->name], (new ListTaxClassesHandler())(new ListTaxClasses())), 'name', 'code');

        self::assertSame('Standardowa', $names['standard']);
        self::assertSame('Książki drukowane', $names['printed_book']);
        self::assertSame('Mapy drukowane', $names['printed_map']);
        self::assertSame('Żywność liofilizowana', $names['freeze_dried_meal']);
        self::assertSame('Batony energetyczne', $names['energy_bar']);
        self::assertSame('Żele energetyczne', $names['energy_gel']);
        self::assertSame('Odżywki białkowe', $names['protein_powder']);
        self::assertSame('Tabletki elektrolitowe', $names['electrolyte_tablet']);
    }
}
