<?php

declare(strict_types=1);

namespace App\Inventory\Application\StockRecords;

/**
 * What a sync of stock records did.
 */
final readonly class StockRecordsSynced
{
    /**
     * @param int $products the products Catalog has among those synced
     * @param int $written  records created (empty) or given their product; 0 on a repeated sync
     * @param int $removed  empty records of variants the products no longer have
     */
    public function __construct(
        public int $products,
        public int $written,
        public int $removed,
    ) {
    }

    /**
     * One line for the output of the commands.
     */
    public function summary(): string
    {
        return \sprintf('products: %d, records written: %d, empty records removed: %d', $this->products, $this->written, $this->removed);
    }

    public function plus(self $other): self
    {
        return new self($this->products + $other->products, $this->written + $other->written, $this->removed + $other->removed);
    }
}
