<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Image\SizedImage;
use App\Catalog\Application\Port\BackofficeProductSource;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Domain\Category\CategoryNotFound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListBackofficeProductsHandler
{
    public function __construct(
        private SearchBackofficeProductsHandler $search,
        private BackofficeProductSource $source,
        private ImageUrlGenerator $imageUrls,
    ) {
    }

    /**
     * The page comes from the search of the price list (one query), the rest of the columns from a second query by the
     * ids of the page.
     *
     * @throws InvalidBackofficeProductSearch
     * @throws InvalidProductCursor
     * @throws CategoryNotFound
     */
    public function __invoke(ListBackofficeProducts $query): BackofficeProductList
    {
        $page = ($this->search)(new SearchBackofficeProducts($query->term, $query->categoryId, $query->after, $query->perPage, $query->status));
        $summaries = $this->source->summaries(array_map(static fn (BackofficeProduct $product): string => $product->id, $page->items));

        $items = [];
        foreach ($page->items as $product) {
            // A product deleted between the two reads (products are never deleted) would simply be missing.
            $summary = $summaries[$product->id] ?? null;
            if (null === $summary) {
                continue;
            }
            $items[] = new BackofficeProductListItem(
                $product->id,
                $product->name,
                $summary['slug'],
                $summary['status'],
                new ProductCategoryRef($summary['mainCategoryId'], $summary['mainCategoryName']),
                $summary['variantCount'],
                null === $summary['imageKey'] ? null : SizedImage::view($this->imageUrls, ImageSize::Thumbnail, $summary['imageKey'], $summary['imageAlt'] ?? ''),
                $summary['updatedAt'],
            );
        }

        return new BackofficeProductList($items, $page->next);
    }
}
