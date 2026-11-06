<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

final readonly class OptionFacet
{
    /**
     * @param list<OptionValue> $values by count descending, then by value
     */
    public function __construct(public string $axis, public array $values)
    {
    }
}
