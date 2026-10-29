<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Port\ProductSearch;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchResult;
use Elastic\Elasticsearch\Client;

final readonly class ElasticsearchProductSearch implements ProductSearch
{
    /**
     * @param int $maxResultWindow the index.max_result_window of the index (Elasticsearch's default is 10 000)
     */
    public function __construct(private Client $client, private string $alias, private int $maxResultWindow = 10_000)
    {
    }

    public function search(?string $categoryId, int $page, int $perPage): SearchResult
    {
        if ($page < 1 || $perPage < 1) {
            throw new \InvalidArgumentException('The page and the page size must be at least 1.');
        }

        // Elasticsearch rejects from + size beyond the result window. A page starting at or after its end only
        // counts the matches; a page crossing it is cut at its end.
        $from = ($page - 1) * $perPage;
        [$from, $size] = $from >= $this->maxResultWindow ? [0, 0] : [$from, min($perPage, $this->maxResultWindow - $from)];

        $body = [
            'query' => ['bool' => ['filter' => null === $categoryId ? [] : [['term' => ['category_ancestor_ids' => $categoryId]]]]],
            'sort' => [['name.sort' => 'asc'], ['id' => 'asc']],
            'track_total_hits' => true,
            'from' => $from,
            'size' => $size,
        ];

        $response = ElasticsearchErrorClassifier::request('search', fn () => $this->client->search(['index' => $this->alias, 'body' => $body]))->asArray();
        $hits = $response['hits'] ?? null;
        $total = \is_array($hits) && \is_array($hits['total'] ?? null) ? ($hits['total']['value'] ?? null) : null;
        if (!\is_int($total) || !\is_array($hits['hits'] ?? null)) {
            throw new SearchIndexError('Elasticsearch returned a search response without hits.');
        }

        $items = [];
        foreach ($hits['hits'] as $hit) {
            $items[] = ProductIndexDefinition::document(\is_array($hit) ? ($hit['_source'] ?? null) : null);
        }

        return new SearchResult($items, $total);
    }
}
