<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Image\SizedImage;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Port\ProductEditSource;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductNotFound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetProductEditViewHandler
{
    public function __construct(
        private ProductEditSource $source,
        private ImageUrlGenerator $imageUrls,
    ) {
    }

    /**
     * @throws ProductNotFound when there is no product with the id, or the id is no id
     */
    public function __invoke(GetProductEditView $query): ProductEditView
    {
        try {
            $id = ProductId::fromString($query->productId);
        } catch (\InvalidArgumentException) {
            throw new ProductNotFound();
        }
        $row = $this->source->find($id) ?? throw new ProductNotFound();

        return new ProductEditView(
            $row['id'],
            $row['version'],
            $row['status'],
            $row['firstPublishedAt'],
            $row['name'],
            $row['slug'],
            null !== $row['firstPublishedAt'],
            $row['shortDescription'],
            $row['mainCategoryId'],
            $row['additionalCategoryIds'],
            $row['taxClass'],
            array_map(
                fn (array $image): ProductEditImage => new ProductEditImage($image['key'], $image['alt'], SizedImage::view($this->imageUrls, ImageSize::Thumbnail, $image['key'], $image['alt'])),
                $row['images'],
            ),
            $row['axes'],
            array_map(static fn (array $variant): ProductEditVariant => new ProductEditVariant($variant['id'], $variant['values']), $row['variants']),
            $row['updatedAt'],
        );
    }
}
