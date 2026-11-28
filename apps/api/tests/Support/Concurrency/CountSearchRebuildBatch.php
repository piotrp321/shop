<?php

declare(strict_types=1);

namespace App\Tests\Support\Concurrency;

/**
 * Counts a written batch of an asynchronous rebuild run, as the handler of the batch does after its write.
 */
final readonly class CountSearchRebuildBatch
{
    public function __construct(public string $runId, public int $batch, public int $documents)
    {
    }
}
