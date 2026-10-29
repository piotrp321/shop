<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;

/**
 * Writes single documents through the alias.
 */
interface ProductIndexer
{
    /**
     * Writes the document unless the index already holds its version or a newer one (then the outcome is Stale).
     *
     * @throws SearchUnavailable
     * @throws SearchIndexError
     */
    public function index(ProductDocument $document): IndexOutcome;

    /**
     * @throws SearchUnavailable
     * @throws SearchIndexError
     */
    public function countDocuments(): int;
}
