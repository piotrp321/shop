<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The shares of the cost of a delivery above 0, by VAT rate in descending order; none for a free delivery. They add up
 * to the cost.
 */
final readonly class DeliveryCostShares
{
    /**
     * @param list<DeliveryCostShare> $shares
     */
    public function __construct(public array $shares)
    {
    }
}
