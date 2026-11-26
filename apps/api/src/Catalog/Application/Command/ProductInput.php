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
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductParameter;
use App\Catalog\Domain\Product\ProductParameters;
use App\Catalog\Domain\Product\ProductRepository;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\Sku;
use App\Catalog\Domain\Product\SkuUsedByAnotherProduct;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\SlugRequired;
use App\Catalog\Domain\Product\UnknownCategory;

/**
 * Turns the data of a product write (CreateProduct, EditProduct) into the values of the domain.
 */
final readonly class ProductInput
{
    public function __construct(private CategoryRepository $categories, private ProductImageStorage $imageStorage)
    {
    }

    /**
     * @param list<array{name: string, value: string}> $parameters in presentation order
     *
     * @throws \InvalidArgumentException
     */
    public static function parameters(array $parameters): ProductParameters
    {
        return ProductParameters::create(array_map(
            static fn (array $parameter): ProductParameter => ProductParameter::of($parameter['name'], $parameter['value']),
            $parameters,
        ));
    }

    /**
     * The check of the SKUs of a write against the variants of the other products, before anything is saved. The variants
     * of the product itself are not taken, so the codes of two of them may be swapped and a repeated write is a conflict
     * of the version, not of the SKU. A write that passed it may still lose a race: the unique index decides then.
     *
     * @throws SkuUsedByAnotherProduct at the first row whose SKU another product has
     */
    public static function refuseSkusOfOtherProducts(ProductRepository $products, ProductId $product, ProductVariants $variants): void
    {
        $skus = [];
        foreach ($variants->toList() as $variant) {
            if (null !== $variant->sku()) {
                $skus[] = $variant->sku();
            }
        }
        if ([] === $skus) {
            return;
        }

        $taken = array_map(static fn (Sku $sku): string => $sku->value(), $products->skusOfOtherProducts($product, $skus));
        foreach ($variants->toList() as $index => $variant) {
            if (null !== $variant->sku() && \in_array($variant->sku()->value(), $taken, true)) {
                throw new SkuUsedByAnotherProduct($index);
            }
        }
    }

    /**
     * Every category is locked against its delete until the end of the transaction of the write (a key share lock on
     * its row), so the delete of a category either counts this product or waits and the write then fails on the
     * missing category, never on the foreign key.
     *
     * @param list<string> $additionalCategoryIds
     *
     * @throws UnknownCategory when the main or an additional category does not exist (the position of the additional one)
     */
    public function categories(string $mainCategoryId, array $additionalCategoryIds): ProductCategories
    {
        $categories = ProductCategories::create(
            CategoryId::fromString($mainCategoryId),
            array_map(CategoryId::fromString(...), $additionalCategoryIds),
        );
        $this->requireCategory($categories->main(), null);
        foreach ($categories->additional() as $index => $categoryId) {
            $this->requireCategory($categoryId, $index);
        }

        return $categories;
    }

    private function requireCategory(CategoryId $id, ?int $index): void
    {
        try {
            $this->categories->lockForAssignment($id);
        } catch (CategoryNotFound) {
            throw new UnknownCategory($index);
        }
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
            $list[] = ProductImage::of(ImageKey::fromString($image['key']), $image['alt'], $position);
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
