<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Indexing;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The indexer of one type of documents in a full rebuild: a module registers it by implementing this interface (the
 * tag comes from it), and {@see IndexDocumentsHandler} hands it the batches of its type.
 */
#[AutoconfigureTag(self::TAG)]
interface DocumentIndexer
{
    public const string TAG = 'app.document_indexer';

    /**
     * The key the batches of this type carry.
     */
    public function type(): string;

    /**
     * Indexes the batch. An exception that implements UnrecoverableExceptionInterface sends the message to `failed`
     * at once; any other is retried by the transport.
     */
    public function index(IndexDocuments $message): void;
}
