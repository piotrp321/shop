<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The authoritative current prices of 1 to 4800 variants (48 products of 100 variants). Read from the Pricing tables
 * in the process with one query, never cached.
 */
final readonly class GetCurrentPrices
{
    public const int MAX_VARIANTS = 4800;

    /**
     * @param list<string> $variantIds variant UUIDs
     */
    public function __construct(public array $variantIds)
    {
    }
}
