<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Finalize the asynchronous rebuild run (verify, switch the alias, delete the previous index), resuming from its saved
 * status. Written to the Doctrine transport `indexing_finalize` in the transaction that moves the run to `finalizing`.
 */
final readonly class FinalizeSearchRebuild
{
    public function __construct(public string $runId)
    {
    }
}
