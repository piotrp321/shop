<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

/**
 * A product without a stored stock has physical = reserved = 0. Movements are only ever appended: nothing here
 * changes or deletes them.
 */
interface StockItemRepository
{
    /**
     * Locks the stored stocks of the products until the end of the current transaction, one after another in
     * ascending product id (so two transactions locking overlapping products in any order never deadlock), and
     * returns them as they are once locked: a stock changed by a transaction that held the lock first is read anew.
     * A product without a stored stock is absent from the result and nothing is locked for it.
     *
     * Must run inside a transaction (command.bus has doctrine_transaction).
     *
     * @param list<ProductId> $ids
     *
     * @return list<StockItem> in ascending product id
     */
    public function lockMany(array $ids): array;

    /**
     * Locks the stock of the product like lockMany() and returns it as it is once locked. A product without a stored
     * stock gets one first, with nothing on the shelf at version 0 (INSERT … ON CONFLICT DO NOTHING): a transaction
     * creating the same stock waits for this one to end, then locks the stock this one left. Version 0 means a stock
     * no change was ever saved for: created by this transaction, or an empty stock stored earlier (the demo data
     * does, see CreateEmptyStock). The caller saves a change to it or leaves it as it is.
     *
     * Must run inside a transaction (command.bus has doctrine_transaction).
     */
    public function lockOrCreate(ProductId $id): StockItem;

    /**
     * The receipt recorded with the key, whatever its product; null when the key was never used. Read without a lock:
     * a receipt with the key committed by a concurrent transaction after this read is refused by save().
     */
    public function findReceipt(ReceiptKey $key): ?StockMovement;

    /**
     * The stored stocks of the products, in one read straight from the store (no cache, no session state, no lock),
     * in no particular order. A product without a stored stock is absent from the result.
     *
     * @param list<ProductId> $ids
     *
     * @return list<StockItem>
     */
    public function findMany(array $ids): array;

    /**
     * Writes a stock locked by lockMany() in this transaction with the version of the stock, and appends its unsaved
     * movements. A stock without unsaved movements is unchanged and nothing is written.
     *
     * @throws ReceiptKeyReused when a receipt with the key of a receipt among the movements is already stored
     * @throws \LogicException  when the stored version is not the one the stock was read with, i.e. it was changed
     *                          without holding its lock
     */
    public function save(StockItem $item): void;
}
