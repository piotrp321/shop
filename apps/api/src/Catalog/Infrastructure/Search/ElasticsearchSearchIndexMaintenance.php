<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\BulkResult;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Application\Search\SetupResult;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Physical indexes named <alias>_<UTC yyyymmddhhmmssmmm>_<6 hex> behind one alias.
 * $client carries the short timeout (alias operations, reads, deletions), $longRequestClient the long one
 * (index creation, bulk writes, refresh).
 *
 * A bulk write retries what failed transiently (items rejected with 429 or es_rejected_execution_exception, whole
 * requests answered with 429, 502, 503 or 504 or lost to a timeout) up to {@see WRITE_ATTEMPTS} times in all, waiting
 * twice as long after each failed attempt. The writes keep version_type external_gte, so repeating a document that
 * a timed-out request already indexed is harmless. An index created for a rebuild is filled with the refresh
 * disabled; {@see verify()} sets the refresh interval of the configuration. {@see setup()} sets it on the index of the alias
 * when that one has another.
 */
final readonly class ElasticsearchSearchIndexMaintenance implements SearchIndexMaintenance
{
    private const string ALIAS_SWITCH_MASTER_TIMEOUT = '1s';
    private const string ALIAS_SWITCH_ACK_TIMEOUT = '1s';
    private const int WRITE_ATTEMPTS = 5;

    /**
     * @param (\Closure(int): void)|null $sleeper          waits the given number of milliseconds between the attempts of a
     *                                                     bulk write; null really sleeps
     * @param int                        $retryBaseDelayMs the wait after the first failed attempt, doubled after each next one
     */
    public function __construct(
        private Client $client,
        private Client $longRequestClient,
        private ProductIndexDefinition $definition,
        private string $alias,
        private ?\Closure $sleeper = null,
        private int $retryBaseDelayMs = 1000,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function setup(): SetupResult
    {
        $targets = $this->aliasTargets();

        if ([] === $targets) {
            if ($this->indexExists($this->alias)) {
                throw new SearchIndexError(\sprintf('A plain index named "%s" exists where the alias should be. It is not managed by the application: delete it, then run catalog:search-index:setup again.', $this->alias));
            }

            return new SetupResult($this->orphans([$this->create(withAlias: true)]));
        }

        if (1 !== \count($targets)) {
            throw new SearchIndexError(\sprintf('The alias "%s" points to %d indexes (%s); it must point to exactly one. Remove the alias from all but one of them with the _aliases API.', $this->alias, \count($targets), implode(', ', $targets)));
        }

        $this->applyRefreshInterval($targets[0]);

        return new SetupResult(
            $this->orphans($targets),
            $this->hasCurrentDefinition($targets[0]) ? null : $targets[0],
        );
    }

    public function recreate(): void
    {
        $targets = $this->aliasTargets();
        if ([] !== $targets) {
            $this->request('remove alias', fn () => $this->client->indices()->updateAliases(['body' => [
                'actions' => [['remove' => ['indices' => $targets, 'alias' => $this->alias]]],
            ]]));
        }

        $indexes = $this->orphans([]);
        if ([] !== $indexes) {
            // Explicit names: wildcard deletion is disabled by default (action.destructive_requires_name).
            $this->request('delete indexes', fn () => $this->longRequestClient->indices()->delete(['index' => implode(',', $indexes)]));
        }

        $this->create(withAlias: true);
    }

    public function activeIndex(): string
    {
        $targets = $this->aliasTargets();
        if (1 !== \count($targets)) {
            throw new SearchIndexError(\sprintf('The alias "%s" must point to exactly one index, it points to %d. Run catalog:search-index:setup.', $this->alias, \count($targets)));
        }

        return $targets[0];
    }

    public function createIndex(): string
    {
        return $this->create(withAlias: false);
    }

    public function write(string $index, array $documents): BulkResult
    {
        $indexed = $stale = $permanent = $transient = [];
        $pending = $documents;
        for ($attempt = 1; [] !== $pending; ++$attempt) {
            try {
                $result = $this->writeOnce($index, $pending);
            } catch (SearchUnavailable $e) {
                if ($attempt >= self::WRITE_ATTEMPTS) {
                    throw new SearchUnavailable(\sprintf('%s (after %d attempts)', $e->getMessage(), $attempt), 0, $e);
                }
                $this->waitBeforeRetry($index, $attempt, \count($pending), $e->getMessage());

                continue;
            }

            $indexed = [...$indexed, ...$result->indexed];
            $stale = [...$stale, ...$result->stale];
            $permanent += $result->permanentFailures;
            $transient = $result->transientFailures;
            // A rebuild ends on the first stale or permanent item, so retrying the rest would only delay the error.
            if ([] === $transient || [] !== $stale || [] !== $permanent) {
                break;
            }
            if ($attempt >= self::WRITE_ATTEMPTS) {
                $transient = array_map(static fn (string $reason): string => \sprintf('%s (after %d attempts)', $reason, $attempt), $transient);
                break;
            }

            $pending = array_values(array_filter($pending, static fn (ProductDocument $document): bool => \array_key_exists($document->id, $transient)));
            $this->waitBeforeRetry($index, $attempt, \count($pending), $result->describeProblems());
        }

        return new BulkResult($indexed, $stale, $transient, $permanent);
    }

    /**
     * @param list<ProductDocument> $documents
     */
    private function writeOnce(string $index, array $documents): BulkResult
    {
        $body = [];
        foreach ($documents as $document) {
            $body[] = ['index' => ['_index' => $index, '_id' => $document->id, 'version' => $document->version, 'version_type' => 'external_gte']];
            $body[] = ProductIndexDefinition::source($document);
        }
        $items = $this->request('bulk write', fn () => $this->longRequestClient->bulk(['body' => $body]))->asArray()['items'] ?? null;
        if (!\is_array($items) || \count($items) !== \count($documents)) {
            throw new SearchIndexError(\sprintf('The bulk response for "%s" does not have one item per document.', $index));
        }

        $indexed = $stale = [];
        $transient = $permanent = [];
        foreach (array_values($items) as $position => $item) {
            $id = $documents[$position]->id;
            $result = self::at($item, 'index');
            $status = self::at($result, 'status');
            $error = self::at($result, 'error');
            match (true) {
                !\is_int($status) => $permanent[$id] = 'no status in the bulk response',
                $status < 300 => $indexed[] = $id,
                409 === $status => $stale[] = $id,
                ElasticsearchErrorClassifier::isTransient($status, ElasticsearchErrorClassifier::errorType($error)) => $transient[$id] = ElasticsearchErrorClassifier::describe($error),
                default => $permanent[$id] = ElasticsearchErrorClassifier::describe($error),
            };
        }

        return new BulkResult($indexed, $stale, $transient, $permanent);
    }

    private function waitBeforeRetry(string $index, int $failedAttempt, int $pendingDocuments, string $problem): void
    {
        $delay = $this->retryBaseDelayMs * 2 ** ($failedAttempt - 1);
        $this->logger->warning('Retrying a bulk write to the search index.', [
            'index' => $index,
            'failed_attempt' => $failedAttempt,
            'documents' => $pendingDocuments,
            'delay_ms' => $delay,
            'problem' => $problem,
        ]);
        null === $this->sleeper ? usleep($delay * 1000) : ($this->sleeper)($delay);
    }

    public function verify(string $index, int $expectedDocuments): void
    {
        if (!$this->indexExists($index)) {
            throw new SearchIndexError(\sprintf('The index "%s" does not exist.', $index));
        }

        // An index created for a rebuild was filled without refreshes; the interval of the configuration is set before the
        // explicit refresh, so what the first listing reads is what the rebuild wrote.
        $this->setRefreshInterval($index);
        $this->request('refresh index', fn () => $this->longRequestClient->indices()->refresh(['index' => $index]));

        // Healthy: every primary shard assigned (yellow or green). A timed-out wait answers 408.
        $health = $this->request('check index health', fn () => $this->client->cluster()->health([
            'index' => $index,
            'wait_for_status' => 'yellow',
            'timeout' => '1s',
        ]), [408])->asArray();
        if (true === ($health['timed_out'] ?? true) || !\in_array($health['status'] ?? null, ['yellow', 'green'], true)) {
            throw new SearchIndexError(\sprintf('The index "%s" has unassigned primary shards.', $index));
        }

        $count = $this->request('count documents', fn () => $this->client->count(['index' => $index]))->asArray()['count'] ?? null;
        if ($count !== $expectedDocuments) {
            throw new SearchIndexError(\sprintf('The index "%s" holds %s documents, %d were written.', $index, \is_int($count) ? (string) $count : 'an unknown number of', $expectedDocuments));
        }

        $this->assertCurrentDefinition($index);
    }

    public function activate(string $index, string $expectedCurrent): ActivationResult
    {
        try {
            $targets = $this->aliasTargets();
        } catch (SearchUnavailable|SearchIndexError) {
            return ActivationResult::NotActivated;
        }
        // Elasticsearch has no compare-and-swap for aliases. The rebuild lock, this check of the exact target and
        // the must_exist remove in the same atomic request together stand in for it.
        if ([$expectedCurrent] !== $targets) {
            return ActivationResult::NotActivated;
        }

        try {
            // Both limits stay below the client's response timeout (app.search.request_timeout, 2 s): a switch still
            // queued on the master when the client gives up is dropped by Elasticsearch instead of being applied
            // after the read-back below, when the rebuild may already have deleted the new index.
            $this->request('switch alias', fn () => $this->client->indices()->updateAliases([
                'master_timeout' => self::ALIAS_SWITCH_MASTER_TIMEOUT,
                'timeout' => self::ALIAS_SWITCH_ACK_TIMEOUT,
                'body' => ['actions' => [
                    ['remove' => ['index' => $expectedCurrent, 'alias' => $this->alias, 'must_exist' => true]],
                    ['add' => ['index' => $index, 'alias' => $this->alias]],
                ]],
            ]));
        } catch (SearchUnavailable|SearchIndexError) {
            // A timeout or a dropped connection leaves the outcome open; a rejection leaves the alias as it was.
            // Either way the alias state read below decides.
        }

        try {
            $targets = $this->aliasTargets();
        } catch (SearchUnavailable|SearchIndexError) {
            return ActivationResult::Unknown;
        }

        return match ($targets) {
            [$index] => ActivationResult::Activated,
            [$expectedCurrent] => ActivationResult::NotActivated,
            default => ActivationResult::Unknown,
        };
    }

    public function deleteIndex(string $index): void
    {
        if (!str_starts_with($index, $this->alias.'_')) {
            throw new SearchIndexError(\sprintf('Refusing to delete "%s": it is not an index of the alias "%s".', $index, $this->alias));
        }
        // Fails when the alias cannot be read: an index the alias might point to is never deleted.
        if (\in_array($index, $this->aliasTargets(), true)) {
            throw new SearchIndexError(\sprintf('Refusing to delete "%s": the alias "%s" points to it.', $index, $this->alias));
        }

        $this->request('delete index', fn () => $this->longRequestClient->indices()->delete(['index' => $index]));
    }

    private function create(bool $withAlias): string
    {
        $index = \sprintf(
            '%s_%s_%s',
            $this->alias,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('YmdHisv'),
            bin2hex(random_bytes(3)),
        );
        $body = $this->definition->body();
        if ($withAlias) {
            // The index and its alias appear together, in one request.
            $body['aliases'] = [$this->alias => new \stdClass()];
        } else {
            // A rebuild fills the index in one go; verify() sets the interval of the configuration.
            $body['settings']['refresh_interval'] = '-1';
        }

        $this->request('create index', fn () => $this->longRequestClient->indices()->create(['index' => $index, 'body' => $body]));

        return $index;
    }

    private function setRefreshInterval(string $index): void
    {
        $this->request('set refresh interval', fn () => $this->longRequestClient->indices()->putSettings([
            'index' => $index,
            'body' => ['index' => ['refresh_interval' => $this->definition->refreshInterval()]],
        ]));
    }

    /**
     * The refresh interval is a dynamic setting, so an index that exists gets the one of the configuration without a rebuild.
     */
    private function applyRefreshInterval(string $index): void
    {
        $settings = $this->request('read refresh interval', fn () => $this->client->indices()->getSettings([
            'index' => $index,
            'name' => 'index.refresh_interval',
        ]))->asArray();

        if (self::at($settings, $index, 'settings', 'index', 'refresh_interval') !== $this->definition->refreshInterval()) {
            $this->setRefreshInterval($index);
        }
    }

    /**
     * @return list<string> the indexes the alias points to, sorted; empty when there is no such alias
     */
    private function aliasTargets(): array
    {
        $response = $this->request('read alias', fn () => $this->client->indices()->getAlias(['name' => $this->alias]), [404]);
        if (404 === $response->getStatusCode()) {
            return [];
        }

        $targets = array_map(strval(...), array_keys($response->asArray()));
        sort($targets);

        return $targets;
    }

    /**
     * @param list<string> $active
     *
     * @return list<string> indexes named <alias>_* other than $active, sorted
     */
    private function orphans(array $active): array
    {
        $response = $this->request('list indexes', fn () => $this->client->cat()->indices([
            'index' => $this->alias.'_*',
            'h' => 'index',
            'format' => 'json',
            'expand_wildcards' => 'all',
        ]));

        $orphans = [];
        foreach ($response->asArray() as $row) {
            if (\is_array($row) && \is_string($row['index'] ?? null) && !\in_array($row['index'], $active, true)) {
                $orphans[] = $row['index'];
            }
        }
        sort($orphans);

        return $orphans;
    }

    private function indexExists(string $index): bool
    {
        // HEAD responses never throw, so the status is checked here: anything but 200 or 404 is a failure.
        $response = $this->request('check index', fn () => $this->client->indices()->exists(['index' => $index]));
        $status = $response->getStatusCode();

        return match (true) {
            200 === $status => true,
            404 === $status => false,
            ElasticsearchErrorClassifier::isTransient($status, null) => throw new SearchUnavailable(\sprintf('Elasticsearch answered %d when checking "%s".', $status, $index)),
            default => throw new SearchIndexError(\sprintf('Elasticsearch answered %d when checking "%s".', $status, $index)),
        };
    }

    private function hasCurrentDefinition(string $index): bool
    {
        $mapping = $this->request('read mapping', fn () => $this->client->indices()->getMapping(['index' => $index]))->asArray();

        return self::at($mapping, $index, 'mappings', '_meta', 'definition_hash') === $this->definition->hash();
    }

    private function assertCurrentDefinition(string $index): void
    {
        if (!$this->hasCurrentDefinition($index)) {
            throw new SearchIndexError(\sprintf('The index "%s" was built from another index definition. Run catalog:reindex to rebuild it with the current one.', $index));
        }
    }

    /**
     * The value at the nested keys of a decoded response, null when any level is missing.
     */
    private static function at(mixed $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @param \Closure(): mixed $send
     * @param list<int>         $acceptedStatuses
     */
    private function request(string $operation, \Closure $send, array $acceptedStatuses = []): Elasticsearch
    {
        return ElasticsearchErrorClassifier::request($operation, $send, $acceptedStatuses);
    }
}
