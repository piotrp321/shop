<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\BackofficeProductSource;
use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Port\ProductTableStatistics;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Category\CategoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCatalogStatisticsHandler
{
    /** The dashboard counts up to this many; more is shown as "1000+". */
    public const int LIMIT = 1000;

    public function __construct(
        private BackofficeProductSource $products,
        private CategoryRepository $categories,
        private ProductIndexer $indexer,
        private ProductTableStatistics $statistics,
    ) {
    }

    /**
     * The number of products is the estimate of the database, never a count of the table; the products without images
     * are counted with the filter of the list they link to, on its partial index.
     */
    public function __invoke(GetCatalogStatistics $query): CatalogStatisticsView
    {
        return new CatalogStatisticsView(
            $this->statistics->estimatedProductCount(),
            $this->categories->count(),
            $query->withoutImages ? $this->products->count(null, null, null, true, self::LIMIT) : null,
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
