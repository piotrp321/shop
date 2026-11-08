<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use Zenstruck\Foundry\ObjectFactory;

/**
 * Builds Product aggregates through Product::create(); tests persist them through the repository.
 * Categories are given as Category aggregates; image keys as a list of alt texts. Without `variantAxes` and `variants`
 * the product has one default variant with a random id (never the id of the product); `variants` is a list of
 * `['id' => string, 'values' => list<string>]`. Products are published by default (`status`), as the stored data is.
 *
 * @extends ObjectFactory<Product>
 */
final class ProductFactory extends ObjectFactory
{
    public static function class(): string
    {
        return Product::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => self::faker()->uuid(),
            'name' => self::faker()->words(3, true),
            'slug' => self::faker()->unique()->slug(3),
            'shortDescription' => '',
            'mainCategory' => CategoryFactory::new(),
            'additionalCategories' => [],
            'taxClass' => TaxClassCode::Standard,
            'imageAlts' => [],
            'variantAxes' => [],
            'variants' => [['id' => self::faker()->uuid(), 'values' => []]],
            'status' => ProductStatus::Published,
            'createdAt' => new \DateTimeImmutable(),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(self::instantiate(...));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function instantiate(array $attributes): Product
    {
        [
            'id' => $id, 'name' => $name, 'slug' => $slug, 'shortDescription' => $shortDescription,
            'mainCategory' => $main, 'additionalCategories' => $additional, 'taxClass' => $taxClass,
            'imageAlts' => $imageAlts, 'variantAxes' => $variantAxes, 'variants' => $variants,
            'status' => $status, 'createdAt' => $createdAt,
        ] = $attributes;
        \assert($status instanceof ProductStatus && $createdAt instanceof \DateTimeImmutable);
        \assert(\is_string($id) && \is_string($name) && \is_string($slug) && \is_string($shortDescription));
        \assert($main instanceof Category && $taxClass instanceof TaxClassCode);
        \assert(\is_array($additional) && array_is_list($additional) && \is_array($imageAlts) && array_is_list($imageAlts));

        \assert(\is_array($variantAxes) && array_is_list($variantAxes) && \is_array($variants) && array_is_list($variants));

        $images = [];
        foreach ($imageAlts as $position => $alt) {
            \assert(\is_string($alt));
            $images[] = new ProductImage(ImageKey::fromString('originals/'.hash('sha256', $id.$position).'.jpg'), $alt, $position);
        }

        return Product::create(
            ProductId::fromString($id),
            new ProductName($name),
            new Slug($slug),
            new ShortDescription($shortDescription),
            ProductCategories::create($main->id(), array_map(
                static fn (mixed $category): CategoryId => $category instanceof Category ? $category->id() : throw new \InvalidArgumentException('Expected a Category.'),
                $additional,
            )),
            $taxClass,
            ProductImages::fromList($images),
            ProductVariants::create(
                VariantAxes::fromList(self::strings($variantAxes)),
                array_map(static function (mixed $variant): ProductVariant {
                    \assert(\is_array($variant) && \is_string($variant['id']) && \is_array($variant['values']) && array_is_list($variant['values']));

                    return new ProductVariant(VariantId::fromString($variant['id']), VariantValues::fromList(self::strings($variant['values'])));
                }, $variants),
            ),
            $status,
            $createdAt,
        );
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_map(static fn (mixed $value): string => \is_string($value) ? $value : throw new \InvalidArgumentException('Expected a string.'), array_values($values));
    }
}
