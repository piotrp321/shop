<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

use App\Catalog\Application\Port\Clock;
use App\Catalog\Application\Port\ProductOfferFacts;
use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Port\SearchIndexRebuildLock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Synchronous rebuild of the search index from the database and the offer facts: a new physical index is filled in
 * batches, verified and the alias is switched to it in one request. A failure of the offer facts stops the rebuild.
 * Every written batch is logged with the documents done so far and their rate.
 * Single writes made while it runs reach the new index through its rebuild alias, which the index has from its creation
 * until the switch (spec docs/features/search/spec.md, "Zapisy w trakcie przebudowy (W19a, M5)").
 */
final readonly class ProductSearchIndexRebuilder
{
    private ProductBatchIndexer $batches;
    private SearchIndexSwitch $switch;

    public function __construct(
        private ProductSearchSource $source,
        private SearchIndexMaintenance $maintenance,
        private SearchIndexRebuildLock $lock,
        ProductDocumentBuilder $builder,
        ProductOfferFacts $offers,
        private Clock $clock,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->batches = new ProductBatchIndexer($maintenance, $builder, $offers);
        $this->switch = new SearchIndexSwitch($maintenance);
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
     * @param (\Closure(): void)|null $before runs under the rebuild lock before the index is replaced (the reset of the
     *                                    catalog empties the database there), so nothing is changed while another
     *                                    rebuild, a setup or an asynchronous run holds the index
     *
     * @throws SearchIndexError when the lock was refused (nothing done) or the alias was not switched to the empty index
     */
    public function reset(?\Closure $before = null): void
    {
        $report = $this->underLock(function () use ($before): RebuildReport {
            if (null !== $before) {
                $before();
            }

            return $this->replaceIndex(null);
        });

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
            return $this->switch->discard($new, RebuildReport::failed($e->getMessage(), $previous, $new));
        } catch (\Throwable $e) {
            $this->switch->discard($new, RebuildReport::failed($e->getMessage(), $previous, $new));
            throw $e;
        }

        return $this->switch->activate($new, $previous, $documents);
    }

    /**
     * Every batch carries the facts of the offers of its products and, as the version of its documents, the time
     * taken before its reads (as {@see ProductIndexing}).
     *
     * @return int the number of documents written
     *
     * @throws SearchUnavailable|SearchIndexError on the first failed request or item; a stale item (a
     *                                            single write through the rebuild alias was newer) is correct
     * @throws OfferFactsUnavailable              when the offers of a batch could not be read
     */
    private function fill(string $index, int $batchSize): int
    {
        $written = 0;
        $afterId = null;
        $startedAt = self::seconds($this->clock->now());
        while (true) {
            $version = ProductIndexing::version($this->clock->now());
            $page = $this->source->page($afterId, $batchSize);
            if ([] === $page) {
                return $written;
            }
            $written += $this->batches->write($index, $page, $version);
            $afterId = $page[array_key_last($page)]->id;
            $elapsed = self::seconds($this->clock->now()) - $startedAt;
            $this->logger->info('Search index rebuild progress.', [
                'index' => $index,
                'documents' => $written,
                'documents_per_second' => $elapsed > 0 ? round($written / $elapsed, 1) : null,
            ]);
        }
    }

    private static function seconds(\DateTimeImmutable $time): float
    {
        return (float) $time->format('U.u');
    }
}
