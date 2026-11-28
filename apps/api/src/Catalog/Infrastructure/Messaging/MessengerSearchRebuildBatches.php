<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Messaging;

use App\Catalog\Application\Port\SearchRebuildBatches;
use App\Catalog\Infrastructure\Search\ProductDocumentIndexer;
use App\Shared\Infrastructure\Indexing\IndexDocuments;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends a batch as IndexDocuments of the type `product` on indexing.bus; the routing puts it on the AMQP transport
 * `indexing`, which waits for the confirmation of the broker.
 */
final readonly class MessengerSearchRebuildBatches implements SearchRebuildBatches
{
    public function __construct(#[Autowire(service: 'indexing.bus')] private MessageBusInterface $indexingBus)
    {
    }

    public function send(string $runId, int $batch, array $ids): void
    {
        $this->indexingBus->dispatch(new IndexDocuments(ProductDocumentIndexer::TYPE, $ids, $runId, $batch));
    }
}
