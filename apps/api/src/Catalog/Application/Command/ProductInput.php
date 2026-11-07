<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Port\ProductImageStorage;
use App\Catalog\Application\Port\ProductImageStorageUnavailable;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\ImageNotFound;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\SlugRequired;

/**
 * Turns the data of a product write (CreateProduct, EditProduct) into the values of the domain.
 */
final readonly class ProductInput
{
    public function __construct(private CategoryRepository $categories, private ProductImageStorage $imageStorage)
    {
    }

    /**
     * @param list<string> $additionalCategoryIds
     *
     * @throws CategoryNotFound when the main or an additional category does not exist
     */
    public function categories(string $mainCategoryId, array $additionalCategoryIds): ProductCategories
    {
        $categories = ProductCategories::create(
            CategoryId::fromString($mainCategoryId),
            array_map(CategoryId::fromString(...), $additionalCategoryIds),
        );
        foreach ($categories->all() as $categoryId) {
            $this->categories->get($categoryId);
        }

        return $categories;
    }

    /**
     * The originals are asked for last, after every rule of the images that needs no storage.
     *
     * @param list<array{key: string, alt: string}> $images in position order
     *
     * @throws ImageNotFound                  when an original is not stored (the position of the first one)
     * @throws ProductImageStorageUnavailable
     */
    public function images(array $images): ProductImages
    {
        $list = [];
        foreach ($images as $position => $image) {
            $list[] = new ProductImage(ImageKey::fromString($image['key']), $image['alt'], $position);
        }

        $images = ProductImages::fromList($list);
        foreach ($images->toList() as $image) {
            if (!$this->imageStorage->exists($image->key()->toString())) {
                throw new ImageNotFound($image->position());
            }
        }

        return $images;
    }

    /**
     * The given slug, or for an empty one the slug of the name (decision K4).
     *
     * @throws SlugRequired when the slug is empty and the name has no letter or digit
     */
    public static function slug(string $slug, string $name): Slug
    {
        if ('' === $slug) {
            $slug = Slug::slugify($name);
            if ('' === $slug) {
                throw new SlugRequired();
            }
        }

        return new Slug($slug);
    }
}
