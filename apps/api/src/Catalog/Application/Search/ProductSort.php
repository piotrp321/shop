<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

enum ProductSort: string
{
    case Relevance = 'relevance';
    case Name = 'name';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
}
