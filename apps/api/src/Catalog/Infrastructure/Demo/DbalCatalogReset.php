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
     * The database goes first, so an event that is still in flight finds no product to index. A failed step
     * leaves the later ones undone; running the reset again finishes the job.
     */
    public function reset(): void
    {
        $this->connection->transactional(static function (Connection $connection): void {
            $connection->executeStatement('TRUNCATE catalog_product_category, catalog_product, catalog_category');
            // Only the relay's queue: events that were never sent to the broker. `failed` stays for inspection.
            $connection->executeStatement('DELETE FROM messenger_messages WHERE queue_name = :queue', ['queue' => 'outbox']);
        });

        $this->indexRebuilder->reset();
        $this->imageStorage->purge();
    }
}
