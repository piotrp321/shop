<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use Elastic\Elasticsearch\Client;

/**
 * Single writes through the alias. require_alias keeps a write from ever creating an index named like the alias,
 * and the external version makes Elasticsearch reject (409) a version not greater than the stored one.
 */
final readonly class ElasticsearchProductIndexer implements ProductIndexer
{
    private const int VERSION_CONFLICT = 409;

    public function __construct(private Client $client, private string $alias)
    {
    }

    public function index(ProductDocument $document): IndexOutcome
    {
        $response = ElasticsearchErrorClassifier::request('index document', fn () => $this->client->index([
            'index' => $this->alias,
            'id' => $document->id,
            'version' => $document->version,
            'version_type' => 'external',
            'require_alias' => true,
            'body' => ProductIndexDefinition::source($document),
        ]), [self::VERSION_CONFLICT]);

        return self::VERSION_CONFLICT === $response->getStatusCode() ? IndexOutcome::Stale : IndexOutcome::Indexed;
    }

    public function countDocuments(): int
    {
        $count = ElasticsearchErrorClassifier::request('count documents', fn () => $this->client->count(['index' => $this->alias]))->asArray()['count'] ?? null;

        return \is_int($count) ? $count : throw new SearchIndexError('Elasticsearch returned no document count.');
    }
}
