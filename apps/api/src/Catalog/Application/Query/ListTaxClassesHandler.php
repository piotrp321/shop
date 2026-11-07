<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Product\TaxClassCode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListTaxClassesHandler
{
    /**
     * @return list<TaxClassView> in the order of the classes of the domain; the only place with their Polish names
     */
    public function __invoke(ListTaxClasses $query): array
    {
        return array_map(static fn (TaxClassCode $code): TaxClassView => new TaxClassView($code->value, match ($code) {
            TaxClassCode::Standard => 'Standardowa',
            TaxClassCode::PrintedBook => 'Książki drukowane',
            TaxClassCode::PrintedMap => 'Mapy drukowane',
            TaxClassCode::FreezeDriedMeal => 'Żywność liofilizowana',
            TaxClassCode::EnergyBar => 'Batony energetyczne',
            TaxClassCode::EnergyGel => 'Żele energetyczne',
            TaxClassCode::ProteinPowder => 'Odżywki białkowe',
            TaxClassCode::ElectrolyteTablet => 'Tabletki elektrolitowe',
        }), TaxClassCode::cases());
    }
}
