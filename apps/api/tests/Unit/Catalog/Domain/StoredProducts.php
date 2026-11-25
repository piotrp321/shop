<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductBrand;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductDescription;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductParameter;
use App\Catalog\Domain\Product\ProductParameters;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;

/**
 * Products as a repository restores them, for the tests of the rules of a stored product.
 */
final class StoredProducts
{
    public const string PRODUCT_ID = '11111111-1111-4111-8111-111111111111';
    public const string CATEGORY_ID = '22222222-2222-4222-8222-222222222222';
    public const string SLUG = 'buty-biegowe';
    public const string FIRST_PUBLISHED_AT = '2026-11-01T10:00:00+00:00';

    /**
     * @param list<string>                                       $imageAlts  one image per alt text
     * @param list<string>                                       $axes
     * @param list<array{id: string, values: list<string>}>|null $variants   null: one default variant
     * @param list<array{name: string, value: string}>           $parameters
     */
    public static function product(
        ProductStatus $status = ProductStatus::Draft,
        ?string $firstPublishedAt = null,
        array $imageAlts = ['front'],
        array $axes = [],
        ?array $variants = null,
        int $version = 3,
        string $brand = '',
        string $description = '',
        array $parameters = [],
    ): Product {
        return Product::reconstitute(
            ProductId::fromString(self::PRODUCT_ID),
            ProductName::of('Buty biegowe'),
            new Slug(self::SLUG),
            ShortDescription::of(''),
            ProductBrand::of($brand),
            ProductDescription::of($description),
            ProductParameters::create(array_map(static fn (array $parameter): ProductParameter => ProductParameter::of($parameter['name'], $parameter['value']), $parameters)),
            ProductCategories::create(CategoryId::fromString(self::CATEGORY_ID)),
            TaxClassCode::Standard,
            self::images($imageAlts),
            self::variants($axes, $variants ?? [['id' => '33333333-3333-4333-8333-333333333333', 'values' => []]]),
            $status,
            null === $firstPublishedAt ? null : new \DateTimeImmutable($firstPublishedAt),
            $version,
        );
    }

    /**
     * @param list<string>                                       $imageAlts
     * @param list<string>                                       $axes
     * @param list<array{id: string, values: list<string>}>|null $variants
     */
    public static function published(array $imageAlts = ['front'], array $axes = [], ?array $variants = null): Product
    {
        return self::product(ProductStatus::Published, self::FIRST_PUBLISHED_AT, $imageAlts, $axes, $variants);
    }

    /**
     * A draft that was published before and then withdrawn.
     *
     * @param list<string>                                       $imageAlts
     * @param list<string>                                       $axes
     * @param list<array{id: string, values: list<string>}>|null $variants
     */
    public static function unpublished(array $imageAlts = ['front'], array $axes = [], ?array $variants = null): Product
    {
        return self::product(ProductStatus::Draft, self::FIRST_PUBLISHED_AT, $imageAlts, $axes, $variants);
    }

    /**
     * @param list<string> $alts
     */
    public static function images(array $alts): ProductImages
    {
        $images = [];
        foreach ($alts as $position => $alt) {
            $images[] = ProductImage::of(ImageKey::fromString('originals/'.hash('sha256', $alt).'.jpg'), $alt, $position);
        }

        return ProductImages::fromList($images);
    }

    /**
     * A published product whose axes and values are read as stored, without the rule of texts (a row stored before a
     * stricter rule).
     *
     * @param list<string>                                  $axes
     * @param list<array{id: string, values: list<string>}> $variants
     */
    public static function publishedAsStored(array $axes, array $variants): Product
    {
        return Product::reconstitute(
            ProductId::fromString(self::PRODUCT_ID),
            ProductName::of('Buty biegowe'),
            new Slug(self::SLUG),
            ShortDescription::of(''),
            ProductBrand::of(''),
            ProductDescription::of(''),
            ProductParameters::create([]),
            ProductCategories::create(CategoryId::fromString(self::CATEGORY_ID)),
            TaxClassCode::Standard,
            self::images(['front']),
            ProductVariants::create(
                VariantAxes::stored($axes),
                array_map(
                    static fn (array $variant): ProductVariant => new ProductVariant(VariantId::fromString($variant['id']), VariantValues::stored($variant['values'])),
                    $variants,
                ),
            ),
            ProductStatus::Published,
            new \DateTimeImmutable(self::FIRST_PUBLISHED_AT),
            3,
        );
    }

    /**
     * @param list<string>                                  $axes
     * @param list<array{id: string, values: list<string>}> $variants
     */
    public static function variants(array $axes, array $variants): ProductVariants
    {
        return ProductVariants::create(
            VariantAxes::fromList($axes),
            array_map(
                static fn (array $variant): ProductVariant => new ProductVariant(VariantId::fromString($variant['id']), VariantValues::fromList($variant['values'])),
                $variants,
            ),
        );
    }
}
