<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;

/**
 * Physical indexes named <alias>_<UTC yyyymmddhhmmssmmm>_<6 hex> behind one alias.
 * $client carries the short timeout (alias operations, reads, deletions), $longRequestClient the long one
 * (index creation, bulk writes, refresh).
 */
final readonly class ElasticsearchSearchIndexMaintenance implements SearchIndexMaintenance
{
    public function __construct(
        private Client $client,
        private Client $longRequestClient,
        private ProductIndexDefinition $definition,
        private string $alias,
    ) {
    }

    public function setup(): array
    {
        $targets = $this->aliasTargets();

        if ([] === $targets) {
            if ($this->indexExists($this->alias)) {
                throw new SearchIndexError(\sprintf('A plain index named "%s" exists where the alias should be. It is not managed by the application: delete it, then run catalog:search-index:setup again.', $this->alias));
            }

            return $this->orphans([$this->create(withAlias: true)]);
        }

        if (1 !== \count($targets)) {
            throw new SearchIndexError(\sprintf('The alias "%s" points to %d indexes (%s); it must point to exactly one. Remove the alias from all but one of them with the _aliases API.', $this->alias, \count($targets), implode(', ', $targets)));
        }

        $this->assertCurrentDefinition($targets[0]);

        return $this->orphans($targets);
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
        }

        $this->request('create index', fn () => $this->longRequestClient->indices()->create(['index' => $index, 'body' => $body]));

        return $index;
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

    private function assertCurrentDefinition(string $index): void
    {
        $mapping = $this->request('read mapping', fn () => $this->client->indices()->getMapping(['index' => $index]))->asArray();
        $hash = self::at($mapping, $index, 'mappings', '_meta', 'definition_hash');

        if ($hash !== $this->definition->hash()) {
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
