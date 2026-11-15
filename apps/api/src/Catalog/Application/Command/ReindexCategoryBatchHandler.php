<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Port\CategoryReindexBatches;
use App\Catalog\Application\Port\CategoryReindexRuns;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Application\Port\StorefrontCatalogCache;
use App\Catalog\Application\Port\SubtreeProductIds;
use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Category\CategoryId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * One batch of the chain that reindexes a category subtree (spec ADMIN-FEATURES, 4 "Odświeżenie sklepu
 * i reindeksacja"), in one transaction of command.bus:
 *
 * - the row of the run is locked first, so a second copy of the batch waits for the first and then sees the number it
 *   committed: a batch other than the next one, or one of a finished or unknown run, ends without effect;
 * - the next products of the subtree after the cursor come from the search index (no SQL index needed, and the cost
 *   of a batch does not depend on the size of the catalog), each written by ProductIndexing (the external version of
 *   the document makes a repeated write harmless); a product the database no longer has is passed over;
 * - the cursor and the number of the next batch are saved and its message sent in the same transaction;
 * - the empty batch finishes the run and invalidates the catalog in the store again (the first invalidation came
 *   with the change, before the documents were rewritten). A failed invalidation throws, so the finish is rolled back
 *   and the retry of the batch tries both again.
 *
 * A failure is retried by the transport `jobs`, then the message goes to `failed` with the error in the log; a permanent
 * failure of the index (SearchIndexError), from the query of the subtree or from a write, goes to `failed` at once. The run
 * stays unfinished and `catalog:reindex` repairs the index.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReindexCategoryBatchHandler
{
    public const int BATCH_SIZE = 500;

    public function __construct(
        private CategoryReindexRuns $runs,
        private SubtreeProductIds $subtree,
        private ProductIndexing $indexing,
        private CategoryReindexBatches $batches,
        private StorefrontCatalogCache $storefront,
        private Clock $clock,
        private int $batchSize = self::BATCH_SIZE,
    ) {
    }

    /**
     * @throws SearchUnavailable|OfferFactsUnavailable a transient failure; the transport retries the batch
     * @throws UnrecoverableMessageHandlingException   a permanent failure of the index; the message goes to `failed`
     * @throws \RuntimeException                       the store did not take the invalidation; the batch is retried
     */
    public function __invoke(ReindexCategoryBatch $message): void
    {
        $run = $this->runs->lock($message->runId);
        if (null === $run || $run->finished || $message->batch !== $run->nextBatch) {
            return;
        }

        try {
            $productIds = $this->subtree->after(CategoryId::fromString($run->categoryId), $run->cursor, $this->batchSize);
            foreach ($productIds as $productId) {
                // Stale and ProductNotFound end like Indexed: the index holds a newer read, or the product is gone.
                $this->indexing->reindex($productId);
            }
        } catch (SearchIndexError $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }

        if ([] === $productIds) {
            $this->runs->finish($run->id, $this->clock->now());
            $this->storefront->invalidateCatalog();

            return;
        }

        $this->runs->advance($run->id, $productIds[\count($productIds) - 1], $run->nextBatch + 1);
        $this->batches->send($run->id, $run->nextBatch + 1);
    }
}
