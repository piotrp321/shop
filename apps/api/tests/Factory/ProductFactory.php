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
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxCategory;
use Zenstruck\Foundry\ObjectFactory;

/**
 * Builds Product aggregates through Product::create(); tests persist them through the repository.
 * Categories are given as Category aggregates; image keys as a list of alt texts.
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
            'taxCategory' => TaxCategory::Standard,
            'imageAlts' => [],
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
            'mainCategory' => $main, 'additionalCategories' => $additional, 'taxCategory' => $taxCategory,
            'imageAlts' => $imageAlts,
        ] = $attributes;
        \assert(\is_string($id) && \is_string($name) && \is_string($slug) && \is_string($shortDescription));
        \assert($main instanceof Category && $taxCategory instanceof TaxCategory);
        \assert(\is_array($additional) && array_is_list($additional) && \is_array($imageAlts) && array_is_list($imageAlts));

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
            $taxCategory,
            ProductImages::fromList($images),
        );
    }
}
