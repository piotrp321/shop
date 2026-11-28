<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Indexing;

/**
 * A batch whose key of the document type no indexer is registered for: never a class to instantiate.
 */
final class UnknownDocumentType extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The batch names a document type without an indexer.');
    }
}
