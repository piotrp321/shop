<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Search\AsyncProductSearchIndexRebuild;
use App\Catalog\Application\Search\ProductRebuildBatch;
use App\Catalog\Application\Search\SearchIndexError;
use App\Shared\Infrastructure\Indexing\DocumentIndexer;
use App\Shared\Infrastructure\Indexing\IndexDocuments;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The batches of the type `product`. A malformed message (no ids, more than {@see MAX_IDS}, an id that is not a UUID)
 * and a permanent failure of the index go to `failed` at once, without a write; a transient failure of the index or of
 * the offers is retried by the transport.
 */
final readonly class ProductDocumentIndexer implements DocumentIndexer
{
    public const string TYPE = 'product';
    public const int MAX_IDS = AsyncProductSearchIndexRebuild::MAX_BATCH_SIZE;

    public function __construct(private ProductRebuildBatch $batch)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function index(IndexDocuments $message): void
    {
        if ([] === $message->ids || \count($message->ids) > self::MAX_IDS || $message->batch < 0) {
            throw new UnrecoverableMessageHandlingException(\sprintf('A batch of products has 1 to %d ids and a number from 0.', self::MAX_IDS));
        }

        try {
            $this->batch->index($message->runId, $message->batch, $message->ids);
        } catch (\InvalidArgumentException|SearchIndexError $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
    }
}
