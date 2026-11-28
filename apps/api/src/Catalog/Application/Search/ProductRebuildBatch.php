<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

use App\Catalog\Application\Port\Clock;
use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Port\SearchRebuildRuns;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * One batch of an asynchronous rebuild run (docs/features/async-indexing/spec.md, "Paczki a stan przebiegu"), always in
 * this order: (1) the run is read without a lock and a run that no longer takes batches ends here, without a write;
 * (2) the version is taken, the products are read and the batch is written to the target index of the run by
 * {@see ProductBatchIndexer}, outside any transaction; (3) the batch is counted once, conditionally on the status, so a
 * batch whose run was aborted, failed or is finalizing meanwhile counts nothing. A repeated delivery writes the same
 * products again with a newer version and counts nothing.
 */
final readonly class ProductRebuildBatch
{
    public function __construct(
        private SearchRebuildRuns $runs,
        private ProductSearchSource $source,
        private ProductBatchIndexer $batches,
        private Clock $clock,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @throws \InvalidArgumentException          when an id is not a UUID
     * @throws SearchUnavailable|SearchIndexError  from the write; nothing was counted
     * @throws OfferFactsUnavailable              nothing was written or counted
     */
    public function index(string $runId, int $batch, array $ids): void
    {
        $run = $this->runs->get($runId);
        if (null === $run || !$run->status->takesBatches()) {
            return;
        }

        $version = ProductIndexing::version($this->clock->now());
        $snapshots = $this->source->findMany($ids);
        $written = $this->batches->write($run->targetIndex, $snapshots, $version);

        $now = $this->clock->now();
        $counted = $this->runs->countBatch($runId, $batch, $written, $now);
        if (null === $counted) {
            return;
        }
        $elapsed = $now->getTimestamp() - $counted->startedAt->getTimestamp();
        $this->logger->info('Search index rebuild progress.', [
            'run' => $runId,
            'index' => $counted->targetIndex,
            'batches' => $counted->doneBatches,
            'total_batches' => $counted->totalBatches,
            'documents' => $counted->documents,
            'documents_per_second' => $elapsed > 0 ? round($counted->documents / $elapsed, 1) : null,
        ]);
    }
}
