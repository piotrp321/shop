<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

/**
 * The tax class of a product: a homogeneous group of goods named after the goods, not after a rate.
 * The rate of a class is decided by Pricing.
 */
enum TaxClassCode: string
{
    case Standard = 'standard';
    case PrintedBook = 'printed_book';
    case PrintedMap = 'printed_map';
    case FreezeDriedMeal = 'freeze_dried_meal';
    case EnergyBar = 'energy_bar';
    case EnergyGel = 'energy_gel';
    case ProteinPowder = 'protein_powder';
    case ElectrolyteTablet = 'electrolyte_tablet';
}
