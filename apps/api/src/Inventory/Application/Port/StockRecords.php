<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\VariantId;

/**
 * The stock records of the variants of products, written without the StockItem aggregate: an empty record changes no
 * level, so nothing is published. Must run inside a transaction (command.bus has doctrine_transaction).
 */
interface StockRecords
{
    /**
     * Takes the lock of every product, held until the transaction ends, so the syncs of one product run one after
     * another. Products of every call are locked in one global order, so syncs of overlapping batches cannot deadlock.
     *
     * @param list<ProductId> $productIds
     *
     * @throws \LogicException outside a transaction, where the lock would guard nothing
     */
    public function lockProducts(array $productIds): void;

    /**
     * Creates an empty record (physical = reserved = 0, version 0) with its product for every variant without one, and
     * writes the product into a record that has none (created earlier by a receipt or a correction). A record that
     * has its product is left as it is.
     *
     * @param array<string, list<VariantId>> $variantsByProduct product UUID => its variants
     *
     * @return int the records created or given their product
     */
    public function ensure(array $variantsByProduct): int;

    /**
     * Removes the records of the products whose variant is not among their variants any more, only those with
     * physical = reserved = 0. Movements are never removed.
     *
     * @param array<string, list<VariantId>> $variantsByProduct product UUID => all its variants
     *
     * @return int the records removed
     */
    public function removeEmptyOfRemovedVariants(array $variantsByProduct): int;
}
