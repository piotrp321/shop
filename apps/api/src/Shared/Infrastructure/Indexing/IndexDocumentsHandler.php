<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Indexing;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Hands a batch to the indexer of its document type, on indexing.bus (no transaction around the writes to the index).
 * The registry of the types is the list of the tagged indexers; a key from the queue only selects one of them.
 */
#[AsMessageHandler(bus: 'indexing.bus')]
final readonly class IndexDocumentsHandler
{
    /** @var array<string, DocumentIndexer> */
    private array $indexers;

    /**
     * @param iterable<DocumentIndexer> $indexers
     *
     * @throws \LogicException when two indexers have the same type
     */
    public function __construct(#[AutowireIterator(DocumentIndexer::TAG)] iterable $indexers)
    {
        $byType = [];
        foreach ($indexers as $indexer) {
            if (isset($byType[$indexer->type()])) {
                throw new \LogicException(\sprintf('Two document indexers have the type "%s".', $indexer->type()));
            }
            $byType[$indexer->type()] = $indexer;
        }
        $this->indexers = $byType;
    }

    /**
     * @throws UnrecoverableMessageHandlingException for a type without an indexer (permanent: straight to `failed`)
     */
    public function __invoke(IndexDocuments $message): void
    {
        $indexer = $this->indexers[$message->documentType] ?? null;
        if (null === $indexer) {
            $unknown = new UnknownDocumentType();

            throw new UnrecoverableMessageHandlingException($unknown->getMessage(), previous: $unknown);
        }

        $indexer->index($message);
    }
}
