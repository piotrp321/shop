<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Search;

use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Pricing\Domain\Promotion\PromotionPriceChangeSource;
use App\Pricing\Domain\Promotion\PromotionPricesChanged;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * A promotion that starts, ends or moves its end changes the prices and the "from" price of its product in the search
 * index: Catalog's ProductIndexing writes the product again from its current state, with the effective prices read
 * now. The event carries the product, so Catalog is not asked for it. Idempotent, because the index keeps the
 * document of the newest read.
 *
 * A change from the demo seed is acked without reindexing: `make seed` rebuilds the index after all the seeds.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class ReindexProductOnPromotionChange
{
    public function __construct(private ProductIndexing $indexing)
    {
    }

    /**
     * @throws SearchUnavailable|OfferFactsUnavailable a transient failure; Messenger retries the message
     * @throws UnrecoverableMessageHandlingException   a permanent failure of the index; the message goes to `failed`
     */
    public function __invoke(PromotionPricesChanged $event): void
    {
        if (PromotionPriceChangeSource::Seed === $event->source) {
            return;
        }

        try {
            // ProductNotFound and Stale are handled messages too: there is nothing (newer) to write.
            $this->indexing->reindex($event->productId);
        } catch (SearchIndexError $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
    }
}
