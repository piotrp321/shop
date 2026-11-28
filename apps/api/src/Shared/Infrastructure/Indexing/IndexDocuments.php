<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Indexing;

/**
 * One batch of a full rebuild of a search index (docs/features/async-indexing/spec.md, "Wiadomość i rejestr typów"):
 * the key of the type of the documents, their ids and the run the batch belongs to. It carries no index name and no
 * data: the indexer of the type reads the target of the run and the current state of the documents when it handles it.
 * Sent to RabbitMQ as JSON (transport `indexing`).
 */
final readonly class IndexDocuments
{
    /**
     * @param string       $documentType the key of a registered {@see DocumentIndexer}, e.g. "product"
     * @param list<string> $ids          1..N ids of the documents of one batch
     * @param int          $batch        the number of the batch in its run, from 0
     */
    public function __construct(
        public string $documentType,
        public array $ids,
        public string $runId,
        public int $batch,
    ) {
    }
}
