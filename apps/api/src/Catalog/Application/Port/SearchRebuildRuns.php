<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Search\SearchRebuildRun;
use App\Catalog\Application\Search\SearchRebuildStatus;

/**
 * The runs of the asynchronous full rebuild of the search index (catalog_search_rebuild_run and its batches). Every
 * method is one short transaction of its own, with no request to the index inside; every change of a status is
 * conditional on the status the caller saw, so of two concurrent callers exactly one changes it.
 */
interface SearchRebuildRuns
{
    /**
     * A new run in `producing`. Called under the {@see SearchIndexRebuildLock}, which also checked that no run is active.
     *
     * @return string the id of the run
     */
    public function start(string $documentType, string $targetIndex, string $previousIndex, int $batchSize, \DateTimeImmutable $now): string;

    public function get(string $runId): ?SearchRebuildRun;

    /**
     * The active run (status not final) of the document type, null when there is none.
     */
    public function active(string $documentType): ?SearchRebuildRun;

    /**
     * The beat of the producer while it sends the batches (only in `producing`).
     *
     * @return bool false when the run is no longer in `producing` (aborted or failed): the producer stops
     */
    public function beat(string $runId, \DateTimeImmutable $now): bool;

    /**
     * Counts a written batch once: a batch already counted, or a run that no longer takes batches, counts nothing. The
     * batch that completes a run whose producer has finished moves it to `finalizing` and sends its finalization in
     * the same transaction.
     *
     * @return SearchRebuildRun|null the run after the count; null when nothing was counted
     */
    public function countBatch(string $runId, int $batch, int $documents, \DateTimeImmutable $now): ?SearchRebuildRun;

    /**
     * The producer sent all its batches: `producing` → `running` with their total, or straight to `finalizing` (with
     * its finalization sent in the same transaction) when every batch was already counted, or there were none.
     *
     * @return bool false when the run was no longer in `producing` (aborted meanwhile)
     */
    public function finishProduction(string $runId, int $totalBatches, \DateTimeImmutable $now): bool;

    /**
     * Moves the run from one of $from to $to (a final $to sets finished_at; $failure is kept only as the first one).
     *
     * @param list<SearchRebuildStatus> $from
     *
     * @return bool false when the run was in none of $from (another caller moved it first)
     */
    public function move(string $runId, array $from, SearchRebuildStatus $to, \DateTimeImmutable $now, ?string $failure = null): bool;
}
