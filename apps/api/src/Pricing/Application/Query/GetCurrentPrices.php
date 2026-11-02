<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The authoritative current prices of 1 to 100 products. Read from the Pricing tables in the process, never cached.
 */
final readonly class GetCurrentPrices
{
    public const int MAX_PRODUCTS = 100;

    /**
     * @param list<string> $productIds product UUIDs
     */
    public function __construct(public array $productIds)
    {
    }
}
