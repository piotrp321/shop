<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Demo;

use App\Catalog\Application\Port\CatalogReset;
use App\Catalog\Application\Port\ProductImageStorage;
use App\Catalog\Application\Search\ProductSearchIndexRebuilder;
use Doctrine\DBAL\Connection;

final readonly class DbalCatalogReset implements CatalogReset
{
    public function __construct(
        private Connection $connection,
        private ProductImageStorage $imageStorage,
        private ProductSearchIndexRebuilder $indexRebuilder,
    ) {
    }

    /**
     * Everything runs under the rebuild lock of the search index, which also refuses while an asynchronous rebuild run
     * is active: a refused reset changes nothing. The database goes first, so an event that is still in flight finds no
     * product to index. The runs of the reindexing of category subtrees go with their batches in the queue `jobs`; a
     * batch already taken by the worker finds no run and ends without effect. A failed step leaves the later ones
     * undone; running the reset again finishes the job.
     */
    public function reset(): void
    {
        $this->indexRebuilder->reset(fn () => $this->connection->transactional(static function (Connection $connection): void {
            $connection->executeStatement('TRUNCATE catalog_product_category, catalog_product_variant, catalog_product, catalog_category_redirect, catalog_category, catalog_category_reindex');
            // The relay's queue (events that were never sent to the broker) and the batches of the reindexing runs.
            // `failed` stays for inspection, the queues of the other modules for their seeds.
            $connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name IN ('outbox', 'jobs')");
        }));
        $this->imageStorage->purge();
    }
}
