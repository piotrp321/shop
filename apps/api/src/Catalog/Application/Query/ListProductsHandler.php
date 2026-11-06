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
        ));

        return new ProductPage(
            array_map($this->item(...), $result->items),
            $query->page,
            $query->perPage,
            $result->total,
        );
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
