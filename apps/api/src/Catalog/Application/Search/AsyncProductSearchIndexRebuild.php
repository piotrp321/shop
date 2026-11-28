<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

use App\Catalog\Application\Port\Clock;
use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Port\SearchIndexRebuildLock;
use App\Catalog\Application\Port\SearchRebuildBatches;
use App\Catalog\Application\Port\SearchRebuildRuns;

/**
 * The asynchronous full rebuild of the product index (docs/features/async-indexing/spec.md, "Przebieg komendy"): the
 * start and the producer of the batches, and the abort of a run. The batches are written and counted by the workers
 * ({@see ProductRebuildBatch}), the run is finalized by FinalizeSearchRebuildHandler.
 *
 * The start excludes every other operation on the index: under the {@see SearchIndexRebuildLock} (which also refuses
 * while a run is active) it creates the new index, with the rebuild alias through which the single writes reach it, and
 * writes the run; it releases the lock only then. From that moment the active run refuses the others.
 */
final readonly class AsyncProductSearchIndexRebuild
{
    public const string DOCUMENT_TYPE = 'product';
    /** The producer beats at least every this many batches and seconds. */
    private const int BEAT_EVERY_BATCHES = 50;
    private const int BEAT_EVERY_SECONDS = 15;

    private SearchIndexSwitch $switch;

    public function __construct(
        private SearchIndexRebuildLock $lock,
        private SearchIndexMaintenance $maintenance,
        private SearchRebuildRuns $runs,
        private ProductSearchSource $source,
        private SearchRebuildBatches $batches,
        private Clock $clock,
    ) {
        $this->switch = new SearchIndexSwitch($maintenance);
    }

    /**
     * Starts a run and sends all its batches. A failure of the broker fails the run and deletes the new index.
     *
     * @throws \InvalidArgumentException when $batchSize is lower than 1
     */
    public function start(int $batchSize): AsyncRebuildStart
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be at least 1.');
        }
        if (!$this->lock->tryAcquire()) {
            $active = $this->runs->active(self::DOCUMENT_TYPE);

            return AsyncRebuildStart::failed(null === $active
                ? 'Another rebuild or setup of the search index is running.'
                : \sprintf('The rebuild run %s is %s; wait for it or abort it (catalog:reindex:abort).', $active->id, $active->status->value));
        }

        try {
            $previous = $this->maintenance->activeIndex();
            $target = $this->maintenance->createIndex();
            try {
                $runId = $this->runs->start(self::DOCUMENT_TYPE, $target, $previous, $batchSize, $this->clock->now());
            } catch (\Throwable $e) {
                $this->switch->discard($target, RebuildReport::failed($e->getMessage()));
                throw $e;
            }
        } catch (SearchUnavailable|SearchIndexError $e) {
            return AsyncRebuildStart::failed($e->getMessage());
        } finally {
            $this->lock->release();
        }

        return $this->produce($runId, $target, $batchSize);
    }

    /**
     * Aborts the active run while it takes batches (producing, running) and then deletes its index. A live run (one that
     * moved within $stallSeconds) needs $force. A run in its finalization is never aborted: the retry of its message
     * finishes it.
     */
    public function abort(bool $force, int $stallSeconds): AbortOutcome
    {
        $run = $this->runs->active(self::DOCUMENT_TYPE);
        if (null === $run) {
            return AbortOutcome::refused('No rebuild run is active.');
        }
        if (!$run->status->takesBatches()) {
            return AbortOutcome::refused(\sprintf('The run %s is %s: its finalization cannot be aborted; it ends by the retry of its message (app:messaging:failed-retry when it is in failed).', $run->id, $run->status->value));
        }
        if (!$force && !$run->isStalled($this->clock->now(), $stallSeconds)) {
            return AbortOutcome::refused(\sprintf('The run %s is alive (it moved at %s); pass --force to abort it anyway.', $run->id, $run->heartbeatAt->format(\DATE_ATOM)));
        }
        if (!$this->runs->move($run->id, [SearchRebuildStatus::Producing, SearchRebuildStatus::Running], SearchRebuildStatus::Aborted, $this->clock->now(), 'Aborted.')) {
            return AbortOutcome::refused(\sprintf('The run %s moved on meanwhile (to its finalization); nothing was aborted.', $run->id));
        }

        $report = $this->switch->discard($run->targetIndex, RebuildReport::failed('Aborted.'));

        return AbortOutcome::aborted($run->id, $run->targetIndex, $report->warnings);
    }

    private function produce(string $runId, string $target, int $batchSize): AsyncRebuildStart
    {
        $batch = 0;
        $afterId = null;
        $beatAt = $this->clock->now()->getTimestamp();
        try {
            while ([] !== ($ids = $this->source->idsAfter($afterId, $batchSize))) {
                $this->batches->send($runId, $batch, $ids);
                ++$batch;
                $afterId = $ids[array_key_last($ids)];
                $now = $this->clock->now();
                if (0 === $batch % self::BEAT_EVERY_BATCHES || $now->getTimestamp() - $beatAt >= self::BEAT_EVERY_SECONDS) {
                    $this->runs->beat($runId, $now);
                    $beatAt = $now->getTimestamp();
                }
            }
        } catch (\Throwable $e) {
            $failure = \sprintf('Sending batch %d failed: %s', $batch, $e->getMessage());
            if ($this->runs->move($runId, [SearchRebuildStatus::Producing], SearchRebuildStatus::Failed, $this->clock->now(), $failure)) {
                $this->switch->discard($target, RebuildReport::failed($failure));
            }

            return AsyncRebuildStart::failed($failure, $runId);
        }

        if (!$this->runs->finishProduction($runId, $batch, $this->clock->now())) {
            return AsyncRebuildStart::failed(\sprintf('The run %s was aborted while its batches were sent.', $runId), $runId);
        }

        return AsyncRebuildStart::produced($runId);
    }
}
