<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * The authoritative stock of 1 to 4800 variants (48 products of 100 variants), read from the Inventory tables in the
 * process with one query, never cached.
 */
final readonly class GetStockLevels
{
    public const int MAX_VARIANTS = 4800;

    /**
     * @param list<string> $variantIds variant UUIDs
     */
    public function __construct(public array $variantIds)
    {
    }
}
