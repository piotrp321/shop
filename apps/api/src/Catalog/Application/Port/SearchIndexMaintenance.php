<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Search\BulkResult;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;

/**
 * Physical indexes behind the search alias. Callers hold the {@see SearchIndexRebuildLock}.
 */
interface SearchIndexMaintenance
{
    /**
     * Creates the alias and its first index when neither exists; otherwise checks them and changes nothing.
     *
     * @return list<string> orphaned indexes of this alias (the alias does not point to them); never deleted here
     *
     * @throws SearchIndexError  when a plain index has the alias name, the alias points to other than one index,
     *                           or the index was built from another definition
     * @throws SearchUnavailable
     */
    public function setup(): array;

    /**
     * Deletes the alias and all its indexes, then creates them again. For disposable (test) indexes only.
     *
     * @throws SearchIndexError
     * @throws SearchUnavailable
     */
    public function recreate(): void;

    /**
     * @return string the single index the alias points to
     *
     * @throws SearchIndexError  when the alias is missing or points to other than one index
     * @throws SearchUnavailable
     */
    public function activeIndex(): string;

    /**
     * Creates a new, empty physical index from the current definition.
     *
     * @return string its name
     *
     * @throws SearchIndexError
     * @throws SearchUnavailable
     */
    public function createIndex(): string;

    /**
     * Writes the documents in one bulk request directly to the physical index.
     *
     * @param list<ProductDocument> $documents
     *
     * @throws SearchIndexError  when the whole request is rejected
     * @throws SearchUnavailable when the whole request fails transiently
     */
    public function write(string $index, array $documents): BulkResult;
}
