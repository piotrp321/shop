<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

use App\Catalog\Application\Port\Clock;
use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Port\ProductOfferFacts;
use App\Catalog\Application\Port\ProductSearchSource;

/**
 * Writes the current state of one product to the search index: Catalog's data and the facts of its offer. Only
 * reads the database, so it runs outside command.bus (no transaction).
 *
 * The version of the document is the time, in microseconds, taken before the reads. A read started later sees at
 * least the same committed data, so of two concurrent handlings the later read wins, and the index rejects the
 * write of the older one (Stale). Clocks of several hosts that drift apart may let an older read through; the next
 * change of the product or a rebuild repairs it.
 */
final readonly class ProductIndexing
{
    public function __construct(
        private ProductSearchSource $source,
        private ProductOfferFacts $offers,
        private ProductIndexer $indexer,
        private ProductDocumentBuilder $documents,
        private Clock $clock,
    ) {
    }

    /**
     * @param string $productId a UUID, case-insensitive
     *
     * @throws \InvalidArgumentException when $productId is not a UUID
     * @throws OfferFactsUnavailable     the offers could not be read; nothing was written
     * @throws SearchUnavailable         a transient failure of the index
     * @throws SearchIndexError          a permanent failure of the index
     */
    public function reindex(string $productId): ReindexOutcome
    {
        $version = self::version($this->clock->now());
        $snapshot = $this->source->find($productId);
        if (null === $snapshot) {
            return ReindexOutcome::ProductNotFound;
        }
        $facts = $this->offers->of([$snapshot->id])[$snapshot->id] ?? null;

        return match ($this->indexer->index($this->documents->build($snapshot, $facts, $version))) {
            IndexOutcome::Indexed => ReindexOutcome::Indexed,
            IndexOutcome::Stale => ReindexOutcome::Stale,
        };
    }

    /**
     * Microseconds since the epoch: the version of the documents written from reads started at $time.
     */
    public static function version(\DateTimeImmutable $time): int
    {
        return (int) $time->format('Uu');
    }
}
