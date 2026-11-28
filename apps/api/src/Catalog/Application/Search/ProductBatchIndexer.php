<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

use App\Catalog\Application\Port\ProductOfferFacts;
use App\Catalog\Application\Port\SearchIndexMaintenance;

/**
 * Writes one batch of a full rebuild to a physical index, the same for the synchronous rebuild and for a batch of the
 * asynchronous one (docs/features/async-indexing/spec.md, "Wspólna logika trybów"): the facts of the offers of the
 * products, the documents and one bulk write.
 */
final readonly class ProductBatchIndexer
{
    public function __construct(
        private SearchIndexMaintenance $maintenance,
        private ProductDocumentBuilder $builder,
        private ProductOfferFacts $offers,
    ) {
    }

    /**
     * @param list<ProductSearchSnapshot> $snapshots
     * @param int                         $version   the version of the documents: the time the caller took before it read
     *                                               the snapshots ({@see ProductIndexing::version()}); never taken here,
     *                                               after the reads
     *
     * @return int the number of documents written; a stale item (a single write through the rebuild alias was newer)
     *             counts, as the index holds the document
     *
     * @throws SearchUnavailable when the request failed transiently or items still failed only transiently
     * @throws SearchIndexError  on a permanent failure of the request or of any item
     * @throws OfferFactsUnavailable              when the offers could not be read; nothing was written
     */
    public function write(string $index, array $snapshots, int $version): int
    {
        if ([] === $snapshots) {
            return 0;
        }
        $facts = $this->offers->of(array_map(static fn (ProductSearchSnapshot $snapshot): string => $snapshot->id, $snapshots));
        $result = $this->maintenance->write($index, array_map(
            fn (ProductSearchSnapshot $snapshot): ProductDocument => $this->builder->build($snapshot, $facts[$snapshot->id] ?? null, $version),
            $snapshots,
        ));
        if ([] === $result->permanentFailures && [] !== $result->transientFailures) {
            // Still failing transiently after the retries of the write: a later delivery may succeed.
            throw new SearchUnavailable(\sprintf('Writing to "%s" failed: %s.', $index, $result->describeProblems()));
        }
        if ($result->hasFailures()) {
            throw new SearchIndexError(\sprintf('Writing to "%s" failed: %s.', $index, $result->describeProblems()));
        }

        return \count($snapshots);
    }
}
