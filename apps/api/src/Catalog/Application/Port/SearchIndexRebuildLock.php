<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * One rebuild (or setup) of the search index at a time, across processes. Released when the holder dies.
 */
interface SearchIndexRebuildLock
{
    /**
     * @return bool false when another process holds the lock
     */
    public function tryAcquire(): bool;

    /**
     * Shares the lock until the end of the current transaction (many holders at once), so no rebuild or setup starts
     * before that transaction ends. A write that adds a document a running rebuild would not count (a new product)
     * takes it first. Outside a transaction it holds nothing past the statement.
     *
     * @return bool false when a rebuild or setup holds the lock
     */
    public function tryShareInTransaction(): bool;

    public function release(): void;
}
