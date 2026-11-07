<?php

declare(strict_types=1);

namespace App\Catalog\Application\EventHandler;

use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\ReindexOutcome;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Product\ProductCreated;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Writes a created product to the search index. The event carries only the id; ProductIndexing reads the current
 * state, and the index rejects the write of an older read. A repeated or reordered event therefore ends here without
 * a retry.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class IndexProduct
{
    public function __construct(private ProductIndexing $indexing)
    {
    }

    /**
     * @throws SearchUnavailable|OfferFactsUnavailable a transient failure; Messenger retries the message
     * @throws UnrecoverableMessageHandlingException   a permanent failure or a product that does not exist; the message goes to `failed`
     */
    public function __invoke(ProductCreated $event): void
    {
        try {
            // Stale is a handled message too: the index already holds the document of a newer read.
            $outcome = $this->indexing->reindex($event->productId);
        } catch (SearchIndexError $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }

        if (ReindexOutcome::ProductNotFound === $outcome) {
            // The catalog has no deletion, so a created product that cannot be read is an inconsistency to look at,
            // not something a retry fixes.
            throw new UnrecoverableMessageHandlingException(\sprintf('Product "%s" from ProductCreated does not exist.', $event->productId));
        }
    }
}
