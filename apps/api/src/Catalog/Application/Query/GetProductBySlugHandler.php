<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Image\SizedImage;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Port\ProductDetailsSource;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetProductBySlugHandler
{
    public function __construct(
        private ProductDetailsSource $source,
        private ImageUrlGenerator $imageUrls,
    ) {
    }

    /**
     * A slug that is not in the format of a Slug names no product, so it gives null without a read.
     */
    public function __invoke(GetProductBySlug $query): ?ProductDetails
    {
        try {
            $slug = new Slug($query->slug);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $row = $this->source->bySlug($slug);
        if (null === $row) {
            return null;
        }

        return new ProductDetails(
            $row['id'],
            $row['slug'],
            $row['name'],
            $row['shortDescription'],
            array_map(static fn (array $category): BreadcrumbItem => new BreadcrumbItem($category['id'], $category['name']), $row['breadcrumb']),
            array_map(fn (array $image): ImageView => SizedImage::view($this->imageUrls, ImageSize::Product, $image['key'], $image['alt']), $row['images']),
            $row['axes'],
            array_map(static function (array $variant): ProductDetailsVariant {
                $id = VariantId::fromString($variant['id']);
                $values = VariantValues::fromList($variant['values']);

                return new ProductDetailsVariant($id->toString(), new ProductVariant($id, $values)->label(), $variant['values']);
            }, $row['variants']),
        );
    }
}
