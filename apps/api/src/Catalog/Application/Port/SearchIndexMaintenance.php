<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\BulkResult;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Application\Search\SetupResult;

/**
 * Physical indexes behind the search alias. Callers hold the {@see SearchIndexRebuildLock}.
 */
interface SearchIndexMaintenance
{
    /**
     * Creates the alias and its first index when neither exists; otherwise checks them and changes only the
     * refresh interval of the active index, which it sets to the configured one when it differs. An index
     * built from another definition is not an error: the result names it, so that the caller can tell to rebuild it.
     *
     * @return SetupResult the orphaned indexes of this alias (never deleted here) and the outdated active index
     *
     * @throws SearchIndexError  when a plain index has the alias name or the alias points to other than one index
     * @throws SearchUnavailable
     */
    public function setup(): SetupResult;

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
     * Creates a new, empty physical index from the current definition, for a rebuild: the index has the rebuild alias,
     * through which the single writes made while it is filled reach it. The rebuild alias is first taken off every
     * other index (one left by a rebuild that crashed).
     *
     * @return string its name
     *
     * @throws SearchIndexError
     * @throws SearchUnavailable
     */
    public function createIndex(): string;

    /**
     * Writes the documents in bulk directly to the physical index. Transient failures (of single documents or of the
     * whole request) are retried until the attempts run out, and only the documents not yet written are repeated. A
     * stale document (the index holds a newer version, written through the rebuild alias) is not retried.
     *
     * @param list<ProductDocument> $documents
     *
     * @return BulkResult the documents that failed, among them the transient ones that were still failing when the
     *                    attempts ran out
     *
     * @throws SearchIndexError  when the whole request is rejected
     * @throws SearchUnavailable when the whole request still fails transiently after the last attempt
     */
    public function write(string $index, array $documents): BulkResult;

    /**
     * Sets the configured refresh interval of the index, refreshes it and checks that it exists, has healthy shards,
     * the current definition and the expected number of documents.
     *
     * @throws SearchIndexError  when any check fails
     * @throws SearchUnavailable
     */
    public function verify(string $index, int $expectedDocuments): void;

    /**
     * Switches the alias from $expectedCurrent to $index and takes the rebuild alias off $index, in one request, then
     * reads back where the alias points.
     * Never throws for search failures: an ambiguous result is {@see ActivationResult::Unknown}.
     */
    public function activate(string $index, string $expectedCurrent): ActivationResult;

    /**
     * Deletes the index with its aliases; an index that does not exist counts as deleted.
     *
     * @throws SearchIndexError  when the alias points to the index (or that cannot be ruled out), or the deletion fails
     * @throws SearchUnavailable
     */
    public function deleteIndex(string $index): void;
}
