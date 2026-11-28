<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * The batches of an asynchronous rebuild run, sent straight to the broker (no outbox, no transaction).
 */
interface SearchRebuildBatches
{
    /**
     * @param list<string> $ids the product ids of the batch
     *
     * @throws \RuntimeException when the broker did not take the batch
     */
    public function send(string $runId, int $batch, array $ids): void;
}
