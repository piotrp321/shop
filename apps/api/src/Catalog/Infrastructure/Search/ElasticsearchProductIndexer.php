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
 * and the external_gte version makes Elasticsearch reject (409) a version lower than the stored one: the version is
 * the time the read of the product started, so a write of an older read never replaces one of a newer read, and two
 * handlings with the same time (the same or newer data) both write.
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
            'version_type' => 'external_gte',
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
