<?php

declare(strict_types=1);

namespace App\Tests\Support\Concurrency;

use App\Catalog\Application\Port\SearchRebuildRuns;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CountSearchRebuildBatchHandler
{
    public function __construct(private SearchRebuildRuns $runs)
    {
    }

    public function __invoke(CountSearchRebuildBatch $message): bool
    {
        return null !== $this->runs->countBatch($message->runId, $message->batch, $message->documents, new \DateTimeImmutable());
    }
}
