<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Search;

use App\Catalog\Application\Query\GetVariant;
use App\Catalog\Application\Query\VariantInfo;
use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\VariantPriceChanged;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A changed price changes the prices and the "from" price of the product in the search index: Catalog's
 * ProductIndexing writes the product again from its current state. The product comes from Catalog (GetVariant on
 * query.bus). Independent of the invalidation of the store's cache; idempotent, because the index keeps the document
 * of the newest read.
 *
 * A change from the demo seed is acked without reindexing: `make seed` rebuilds the index after all the seeds.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final class ReindexProductOnPriceChange
{
    use HandleTrait;

    public function __construct(
        #[Autowire(service: 'query.bus')] MessageBusInterface $queryBus,
        private readonly ProductIndexing $indexing,
    ) {
        $this->messageBus = $queryBus;
    }

    /**
     * @throws SearchUnavailable|OfferFactsUnavailable a transient failure; Messenger retries the message
     * @throws UnrecoverableMessageHandlingException   a permanent failure of the index; the message goes to `failed`
     */
    public function __invoke(VariantPriceChanged $event): void
    {
        if (PriceChangeSource::Seed === $event->source) {
            return;
        }
        $variant = $this->handle(new GetVariant($event->variantId));
        \assert(null === $variant || $variant instanceof VariantInfo);
        // A variant Catalog does not know has no product to reindex.
        if (null === $variant) {
            return;
        }

        try {
            // ProductNotFound and Stale are handled messages too: there is nothing (newer) to write.
            $this->indexing->reindex($variant->productId);
        } catch (SearchIndexError $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
    }
}
