<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

/**
 * Makes the stock records of the products match their variants as Catalog has them: an empty record (0/0, version 0,
 * no history) for a variant without one, the product written into a record without it, and the empty records of
 * variants the product no longer has removed. A record with stock (physical or reserved above 0) always stays, and so
 * does every movement. Sent by StockRecordSync, which read the variants from Catalog.
 */
final readonly class SyncStockRecords
{
    public const int MAX_PRODUCTS = 100;

    /**
     * @param array<string, list<string>> $variantsByProduct product UUID => the UUIDs of all its variants
     */
    public function __construct(public array $variantsByProduct)
    {
    }
}
