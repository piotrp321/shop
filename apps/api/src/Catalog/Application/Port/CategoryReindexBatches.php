<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * Sends the message of a batch of a reindexing run, in the caller's transaction (the Doctrine transport `jobs` on the
 * same connection), so the message exists exactly when the state of the run that expects it is committed.
 */
interface CategoryReindexBatches
{
    public function send(string $runId, int $batch): void;
}
