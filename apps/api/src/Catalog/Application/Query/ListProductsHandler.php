<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Image\ListingImage;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Port\ProductSearch;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListProductsHandler
{
    public function __construct(
        private ProductSearch $search,
        private ImageUrlGenerator $imageUrls,
        private CategoryRepository $categories,
    ) {
    }

    /**
     * @throws CategoryNotFound  when the filter names a category that is not in the database
     * @throws SearchUnavailable
     * @throws SearchIndexError
     */
    public function __invoke(ListProducts $query): ProductPage
    {
        if (null !== $query->categoryId) {
            // The database decides whether the category exists; the search index is only a read model.
            $this->categories->get(CategoryId::fromString($query->categoryId));
        }
        $facetCategories = $this->facetCategories($query->categoryId);

        $result = $this->search->search(new ProductSearchCriteria(
            $query->page,
            $query->perPage,
            $query->categoryId,
            $query->phrase,
            $query->options,
            $query->priceMin,
            $query->priceMax,
            $query->availableOnly,
            $query->sort,
            array_map(static fn (CategoryNode $node): string => $node->id, $facetCategories),
        ));

        return new ProductPage(
            array_map($this->item(...), $result->items),
            $query->page,
            $query->perPage,
            $result->total,
            new ProductFacets(
                $this->categoryFacets($facetCategories, $result->facets->categoryCounts),
                $result->facets->options,
                $result->facets->price,
                $result->facets->availableCount,
            ),
        );
    }

    /**
     * The children of the filter category, the roots without one, in tree order.
     *
     * @return list<CategoryNode>
     */
    private function facetCategories(?string $categoryId): array
    {
        $nodes = CategoryTreeBuilder::build($this->categories->all());
        if (null === $categoryId) {
            return $nodes;
        }

        return self::find($nodes, $categoryId)->children ?? [];
    }

    /**
     * @param list<CategoryNode> $nodes
     */
    private static function find(array $nodes, string $id): ?CategoryNode
    {
        foreach ($nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
            $found = self::find($node->children, $id);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<CategoryNode> $nodes
     * @param array<string, int> $counts
     *
     * @return list<CategoryFacet>
     */
    private function categoryFacets(array $nodes, array $counts): array
    {
        $facets = [];
        foreach ($nodes as $node) {
            $count = $counts[$node->id] ?? 0;
            if ($count > 0) {
                $facets[] = new CategoryFacet($node->id, $node->name, $node->slug, $node->path, $count);
            }
        }

        return $facets;
    }

    private function item(ProductDocument $document): ProductListItem
    {
        return new ProductListItem(
            $document->id,
            $document->name,
            $document->slug,
            $document->shortDescription,
            null === $document->mainImage ? null : $this->image($document->mainImage['key'], $document->mainImage['alt']),
        );
    }

    private function image(string $key, string $alt): ImageView
    {
        return ListingImage::view($this->imageUrls, $key, $alt);
    }
}
