<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Port\SubtreeProductIds;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Domain\Category\CategoryId;
use Elastic\Elasticsearch\Client;

/**
 * The ids of the documents with the category in category_ancestor_ids (a term filter, no scoring), sorted by the id
 * (a keyword) and paged with search_after, without the source: the cost of a page does not depend on how deep it is
 * nor on the size of the catalog. Every status, so drafts are rewritten too.
 */
final readonly class ElasticsearchSubtreeProductIds implements SubtreeProductIds
{
    public function __construct(private Client $client, private string $alias)
    {
    }

    public function after(CategoryId $categoryId, ?string $afterId, int $limit): array
    {
        $body = [
            'size' => $limit,
            '_source' => false,
            'track_total_hits' => false,
            'query' => ['bool' => ['filter' => [['term' => ['category_ancestor_ids' => $categoryId->toString()]]]]],
            'sort' => [['id' => 'asc']],
        ];
        if (null !== $afterId) {
            $body['search_after'] = [strtolower($afterId)];
        }

        $response = ElasticsearchErrorClassifier::request('search subtree products', fn () => $this->client->search(['index' => $this->alias, 'body' => $body]))->asArray();
        $hits = \is_array($response['hits'] ?? null) ? ($response['hits']['hits'] ?? null) : null;
        if (!\is_array($hits)) {
            throw new SearchIndexError('Elasticsearch returned a search response without hits.');
        }

        $ids = [];
        foreach ($hits as $hit) {
            $id = \is_array($hit) && \is_array($hit['sort'] ?? null) ? ($hit['sort'][0] ?? null) : null;
            $ids[] = \is_string($id) ? $id : throw new SearchIndexError('A subtree hit has no id to sort by.');
        }

        return $ids;
    }
}
