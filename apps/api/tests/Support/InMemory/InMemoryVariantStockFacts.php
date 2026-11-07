<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\VariantStockFacts;

/**
 * Stock facts from a list of variant ids in use; records every call.
 */
final class InMemoryVariantStockFacts implements VariantStockFacts
{
    /** @var list<list<string>> the variant ids of every call */
    public array $asked = [];

    /**
     * @param list<string> $inUse
     */
    public function __construct(public array $inUse = [])
    {
    }

    public function inUse(array $variantIds): array
    {
        $this->asked[] = $variantIds;

        return array_values(array_intersect($variantIds, $this->inUse));
    }
}
