<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use Elastic\Elasticsearch\Client;

/**
 * Single writes: one _bulk with an item for the rebuild alias first and one for the alias, with the same version
 * (spec "Zapisy w trakcie przebudowy (W19a, M5)"). Elasticsearch resolves the aliases of all items of a bulk in one
 * cluster state, so a write never reaches the previous index only while a rebuild switches the alias.
 *
 * require_alias keeps a write from ever creating an index named like either alias, and the external_gte version makes
 * Elasticsearch reject (409) a version lower than the stored one: the version is the time the read of the product
 * started, so a write of an older read never replaces one of a newer read, and two handlings with the same time (the
 * same or newer data) both write.
 *
 * The item of the alias has the outcome. The item of the rebuild alias is skipped when that alias does not exist (404
 * index_not_found_exception: no rebuild runs) or the new index holds a newer version (409); any other failure of it
 * fails the write like a failure of the item of the alias, so the message is retried and both items are written again.
 */
final readonly class ElasticsearchProductIndexer implements ProductIndexer
{
    private const int VERSION_CONFLICT = 409;
    private const int NOT_FOUND = 404;
    private const string INDEX_NOT_FOUND = 'index_not_found_exception';

    public function __construct(private Client $client, private string $alias)
    {
    }

    public function index(ProductDocument $document): IndexOutcome
    {
        $rebuildAlias = ElasticsearchSearchIndexMaintenance::rebuildAliasOf($this->alias);
        $item = ['_id' => $document->id, 'version' => $document->version, 'version_type' => 'external_gte', 'require_alias' => true];
        $source = ProductIndexDefinition::source($document);
        $items = ElasticsearchErrorClassifier::request('index document', fn () => $this->client->bulk(['body' => [
            ['index' => ['_index' => $rebuildAlias, ...$item]],
            $source,
            ['index' => ['_index' => $this->alias, ...$item]],
            $source,
        ]]))->asArray()['items'] ?? null;
        if (!\is_array($items) || 2 !== \count($items)) {
            throw new SearchIndexError('The bulk response of a single write does not have two items.');
        }
        [$rebuild, $main] = array_values($items);

        [$status, $error] = self::result($rebuild);
        if (!($status < 300 || self::VERSION_CONFLICT === $status || self::isIndexNotFound($status, $error))) {
            throw self::failure('index document through the rebuild alias', $status, $error);
        }

        [$status, $error] = self::result($main);

        return match (true) {
            $status < 300 => IndexOutcome::Indexed,
            self::VERSION_CONFLICT === $status => IndexOutcome::Stale,
            // The alias was resolved to an index deleted before the item reached it: a rebuild switched the alias
            // and deleted the previous index. A retry writes through the alias to the index it points to now. A missing
            // alias (require_alias) names the alias itself and stays a permanent failure.
            self::isIndexNotFound($status, $error) && ($error['index'] ?? null) !== $this->alias => throw new SearchUnavailable(\sprintf(
                'The index of the alias was deleted during the write (index document): %s',
                ElasticsearchErrorClassifier::describe($error),
            )),
            default => throw self::failure('index document', $status, $error),
        };
    }

    public function countDocuments(): int
    {
        $count = ElasticsearchErrorClassifier::request('count documents', fn () => $this->client->count(['index' => $this->alias]))->asArray()['count'] ?? null;

        return \is_int($count) ? $count : throw new SearchIndexError('Elasticsearch returned no document count.');
    }

    /**
     * @return array{int, mixed} the status and the error of a bulk item
     */
    private static function result(mixed $item): array
    {
        $result = \is_array($item) ? ($item['index'] ?? null) : null;
        $status = \is_array($result) ? ($result['status'] ?? null) : null;
        if (!\is_int($status)) {
            throw new SearchIndexError('The bulk response of a single write has an item without a status.');
        }

        return [$status, $result['error'] ?? null];
    }

    /**
     * @phpstan-assert-if-true array<mixed> $error
     */
    private static function isIndexNotFound(int $status, mixed $error): bool
    {
        return self::NOT_FOUND === $status && \is_array($error) && self::INDEX_NOT_FOUND === ElasticsearchErrorClassifier::errorType($error);
    }

    private static function failure(string $operation, int $status, mixed $error): SearchUnavailable|SearchIndexError
    {
        $message = \sprintf('Elasticsearch rejected the write (%s): %d %s', $operation, $status, ElasticsearchErrorClassifier::describe($error));

        return ElasticsearchErrorClassifier::isTransient($status, ElasticsearchErrorClassifier::errorType($error))
            ? new SearchUnavailable($message)
            : new SearchIndexError($message);
    }
}
