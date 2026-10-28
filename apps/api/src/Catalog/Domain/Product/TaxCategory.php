<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

enum TaxCategory: string
{
    case Standard = 'standard';
    case Food = 'food';
    case FoodSupplement = 'food_supplement';
    case PrintedPublication = 'printed_publication';
}
