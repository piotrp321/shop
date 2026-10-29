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

    public function release(): void;
}
