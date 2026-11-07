<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

use App\Catalog\Application\Port\Clock;
use App\Catalog\Application\Port\ProductOfferFacts;
use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Port\SearchIndexRebuildLock;

/**
 * Synchronous rebuild of the search index from the database and the offer facts: a new physical index is filled in
 * batches, verified and the alias is switched to it in one request. A failure of the offer facts stops the rebuild.
 * Not an online rebuild: writes made through the alias while it runs go to the previous index (see the spec,
 * "Ograniczenia tej przebudowy").
 */
final readonly class ProductSearchIndexRebuilder
{
    public function __construct(
        private ProductSearchSource $source,
        private SearchIndexMaintenance $maintenance,
        private SearchIndexRebuildLock $lock,
        private ProductDocumentBuilder $builder,
        private ProductOfferFacts $offers,
        private Clock $clock,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when $batchSize is lower than 1
     */
    public function rebuild(int $batchSize): RebuildReport
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be at least 1.');
        }

        return $this->underLock(fn (): RebuildReport => $this->replaceIndex($batchSize));
    }

    /**
     * Switches the alias to a new, empty index and deletes the previous one. The alias never disappears.
     *
     * @throws SearchIndexError when the alias was not switched to the empty index
     */
    public function reset(): void
    {
        $report = $this->underLock(fn (): RebuildReport => $this->replaceIndex(null));

        if (ActivationResult::Activated !== $report->outcome) {
            throw new SearchIndexError('The search index was not reset: '.$report->failure);
        }
    }

    /**
     * @param \Closure(): RebuildReport $run
     */
    private function underLock(\Closure $run): RebuildReport
    {
        if (!$this->lock->tryAcquire()) {
            return RebuildReport::failed('Another rebuild or setup of the search index is running.');
        }

        try {
            return $run();
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param int|null $batchSize null leaves the new index empty
     */
    private function replaceIndex(?int $batchSize): RebuildReport
    {
        try {
            $previous = $this->maintenance->activeIndex();
        } catch (SearchUnavailable|SearchIndexError $e) {
            return RebuildReport::failed($e->getMessage());
        }

        try {
            // A creation that timed out may still have created the index; setup lists it as an orphan.
            $new = $this->maintenance->createIndex();
        } catch (SearchUnavailable|SearchIndexError $e) {
            return RebuildReport::failed($e->getMessage(), $previous);
        }

        try {
            $documents = null === $batchSize ? 0 : $this->fill($new, $batchSize);
            $this->maintenance->verify($new, $documents);
        } catch (SearchUnavailable|SearchIndexError|OfferFactsUnavailable $e) {
            return $this->discard($new, RebuildReport::failed($e->getMessage(), $previous, $new));
        } catch (\Throwable $e) {
            $this->discard($new, RebuildReport::failed($e->getMessage(), $previous, $new));
            throw $e;
        }

        return match ($this->maintenance->activate($new, $previous)) {
            ActivationResult::Activated => $this->deletePrevious(RebuildReport::activated($previous, $new, $documents)),
            ActivationResult::NotActivated => $this->discard($new, RebuildReport::failed(
                \sprintf('The alias was not switched; it still points to "%s".', $previous),
                $previous,
                $new,
            )),
            ActivationResult::Unknown => RebuildReport::unknown($previous, $new),
        };
    }

    /**
     * Every batch carries the facts of the offers of its products and, as the version of its documents, the time
     * taken before its reads (as {@see ProductIndexing}).
     *
     * @return int the number of documents written
     *
     * @throws SearchUnavailable|SearchIndexError on the first failed request or item, including a stale item
     *                                            (a fresh index should never have one)
     * @throws OfferFactsUnavailable              when the offers of a batch could not be read
     */
    private function fill(string $index, int $batchSize): int
    {
        $written = 0;
        $afterId = null;
        while (true) {
            $version = ProductIndexing::version($this->clock->now());
            $page = $this->source->page($afterId, $batchSize);
            if ([] === $page) {
                return $written;
            }
            $facts = $this->offers->of(array_map(static fn (ProductSearchSnapshot $snapshot): string => $snapshot->id, $page));
            $result = $this->maintenance->write($index, array_map(
                fn (ProductSearchSnapshot $snapshot): ProductDocument => $this->builder->build($snapshot, $facts[$snapshot->id] ?? null, $version),
                $page,
            ));
            if (!$result->allIndexed()) {
                throw new SearchIndexError(\sprintf('Writing to "%s" failed: %s.', $index, $result->describeProblems()));
            }
            $written += \count($page);
            $afterId = $page[array_key_last($page)]->id;
        }
    }

    private function discard(string $new, RebuildReport $report): RebuildReport
    {
        try {
            $this->maintenance->deleteIndex($new);
        } catch (SearchUnavailable|SearchIndexError $e) {
            return $report->withWarning(\sprintf('The new index "%s" could not be deleted: %s', $new, $e->getMessage()));
        }

        return $report;
    }

    private function deletePrevious(RebuildReport $report): RebuildReport
    {
        \assert(null !== $report->previousIndex);
        try {
            $this->maintenance->deleteIndex($report->previousIndex);
        } catch (SearchUnavailable|SearchIndexError $e) {
            return $report->withWarning(\sprintf(
                'The previous index "%s" could not be deleted and stays as an orphan: %s',
                $report->previousIndex,
                $e->getMessage(),
            ));
        }

        return $report;
    }
}
