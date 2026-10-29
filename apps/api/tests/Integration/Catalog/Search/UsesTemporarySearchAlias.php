<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use App\Catalog\Infrastructure\Search\ElasticsearchErrorClassifier;
use App\Catalog\Infrastructure\Search\ElasticsearchSearchIndexMaintenance;
use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\Attributes\After;
use Psr\Http\Client\ClientInterface;

/**
 * Each test gets its own alias (test_<random>, never matching test_products_*) on the real Elasticsearch;
 * every index of the alias is deleted after the test.
 */
trait UsesTemporarySearchAlias
{
    private ?string $temporaryAlias = null;

    protected function alias(): string
    {
        return $this->temporaryAlias ??= 'test_'.bin2hex(random_bytes(6));
    }

    protected static function clientFactory(): ElasticsearchClientFactory
    {
        $url = $_SERVER['ELASTICSEARCH_URL'] ?? getenv('ELASTICSEARCH_URL');
        self::assertIsString($url, 'ELASTICSEARCH_URL is not set.');

        return new ElasticsearchClientFactory($url, 1.0);
    }

    protected static function esClient(): Client
    {
        return self::clientFactory()->create(30.0);
    }

    protected function maintenance(?ClientInterface $httpClient = null, ?ProductIndexDefinition $definition = null): ElasticsearchSearchIndexMaintenance
    {
        $factory = self::clientFactory();

        return new ElasticsearchSearchIndexMaintenance(
            null === $httpClient ? $factory->create(2.0) : $factory->createWith($httpClient),
            $factory->create(30.0),
            $definition ?? new ProductIndexDefinition(1, 0),
            $this->alias(),
        );
    }

    /**
     * @return list<string> the indexes the alias points to, sorted
     */
    protected function aliasTargets(): array
    {
        $client = self::esClient();
        $response = ElasticsearchErrorClassifier::request('read alias', fn () => $client->indices()->getAlias(['name' => $this->alias()]), [404]);
        if (404 === $response->getStatusCode()) {
            return [];
        }
        $targets = array_map('strval', array_keys($response->asArray()));
        sort($targets);

        return $targets;
    }

    /**
     * @return list<string> every index named <alias>_*, sorted
     */
    protected function aliasIndexes(): array
    {
        $response = self::esClient()->cat()->indices(['index' => $this->alias().'_*', 'h' => 'index', 'format' => 'json', 'expand_wildcards' => 'all']);
        \assert($response instanceof Elasticsearch);
        $names = [];
        foreach ($response->asArray() as $row) {
            \assert(\is_array($row) && \is_string($row['index']));
            $names[] = $row['index'];
        }
        sort($names);

        return $names;
    }

    protected function indexExists(string $index): bool
    {
        $response = self::esClient()->indices()->exists(['index' => $index]);
        \assert($response instanceof Elasticsearch);

        return $response->asBool();
    }

    #[After]
    protected function deleteTemporaryIndexes(): void
    {
        if (null === $this->temporaryAlias) {
            return;
        }
        $names = $this->aliasIndexes();
        // A plain index named like the alias (not the alias itself).
        if ([] === $this->aliasTargets() && $this->indexExists($this->temporaryAlias)) {
            $names[] = $this->temporaryAlias;
        }
        if ([] !== $names) {
            self::esClient()->indices()->delete(['index' => implode(',', $names)]);
        }
    }
}
