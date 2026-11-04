<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

/**
 * Stores the stock of a variant with nothing on the shelf, at version 0 and without a history entry (nothing changed
 * yet). The demo data uses it for the variants that start out of stock.
 */
final readonly class CreateEmptyStock
{
    /**
     * @param string $variantId the variant UUID
     */
    public function __construct(public string $variantId)
    {
    }
}
