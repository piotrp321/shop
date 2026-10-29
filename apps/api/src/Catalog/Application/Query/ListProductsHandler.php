<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Port\ProductSearch;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListProductsHandler
{
    public function __construct(
        private ProductSearch $search,
        private ImageUrlGenerator $imageUrls,
    ) {
    }

    /**
     * @throws SearchUnavailable
     * @throws SearchIndexError
     */
    public function __invoke(ListProducts $query): ProductPage
    {
        $result = $this->search->search($query->categoryId, $query->page, $query->perPage);

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
        $size = ImageSize::Listing;
        $variants = [];
        foreach ($size->formats() as $format) {
            $widths = $size->widths();
            sort($widths);
            foreach ($widths as $width) {
                $variants[$format->value][] = ['width' => $width, 'url' => $this->imageUrls->url($key, $size, $width, $format)];
            }
        }

        return new ImageView($alt, $size->value, $size->aspectRatio(), $variants);
    }
}
