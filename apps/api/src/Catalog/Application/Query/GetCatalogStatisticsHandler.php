<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Product\ProductRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCatalogStatisticsHandler
{
    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
        private ProductIndexer $indexer,
    ) {
    }

    public function __invoke(GetCatalogStatistics $query): CatalogStatisticsView
    {
        return new CatalogStatisticsView(
            $this->products->count(),
            $this->categories->count(),
            $this->products->countWithoutImages(),
            $this->indexedProducts(),
        );
    }

    /**
     * The search index only mirrors the catalogue, so a failing count must not fail the statistics: it is unknown.
     */
    private function indexedProducts(): ?int
    {
        try {
            return $this->indexer->countDocuments();
        } catch (SearchUnavailable|SearchIndexError) {
            return null;
        }
    }
}
