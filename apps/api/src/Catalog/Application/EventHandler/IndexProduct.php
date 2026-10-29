<?php

declare(strict_types=1);

namespace App\Catalog\Application\EventHandler;

use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Product\ProductCreated;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Writes a created product to the search index. The event carries only the id; the handler reads the current state
 * with its version, and the index rejects a version it already holds or a newer one. A repeated or reordered event
 * therefore ends here without a retry.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class IndexProduct
{
    public function __construct(
        private ProductSearchSource $source,
        private ProductIndexer $indexer,
        private ProductDocumentBuilder $documents,
    ) {
    }

    /**
     * @throws SearchUnavailable                     a transient failure; Messenger retries the message
     * @throws UnrecoverableMessageHandlingException a permanent failure or a product that does not exist; the message goes to `failed`
     */
    public function __invoke(ProductCreated $event): void
    {
        $snapshot = $this->source->find($event->productId);
        if (null === $snapshot) {
            // The catalog has no deletion, so a created product that cannot be read is an inconsistency to look at,
            // not something a retry fixes.
            throw new UnrecoverableMessageHandlingException(\sprintf('Product "%s" from ProductCreated does not exist.', $event->productId));
        }

        try {
            // Stale is a handled message too: the index already holds this version or a newer one.
            $this->indexer->index($this->documents->build($snapshot));
        } catch (SearchIndexError $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
    }
}
