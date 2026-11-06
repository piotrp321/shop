<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * Gross prices in grosze.
 */
final readonly class PriceRange
{
    public function __construct(public int $min, public int $max)
    {
    }
}
