<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Application\Port\DemoCatalogSource;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxCategory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The demo catalog from apps/api/fixtures/catalog.yaml and the photos in apps/api/fixtures/images.
 */
final readonly class YamlDemoCatalogSource implements DemoCatalogSource
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/catalog.yaml')]
        private string $definitionPath,
        #[Autowire('%kernel.project_dir%/fixtures/images')]
        private string $imagesDir,
        private DemoCatalogExpander $expander = new DemoCatalogExpander(),
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the definition is malformed, breaks a domain rule (names, slug, tax
     *                                   category, description, photo alt, categories) or a photo file is missing
     */
    public function load(): DemoCatalog
    {
        $catalog = $this->expander->expand(DemoCatalogDefinition::fromFile($this->definitionPath), $this->imagesDir);
        $this->validateAgainstDomain($catalog);
        foreach ($catalog->photoPaths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw new \InvalidArgumentException(\sprintf('The demo photo "%s" does not exist or is not readable.', $path));
            }
        }

        return $catalog;
    }

    /**
     * Builds the domain value objects of every category and product, so a typo is found before the catalog is reset.
     */
    private function validateAgainstDomain(DemoCatalog $catalog): void
    {
        foreach ($catalog->categories as $category) {
            $this->check(\sprintf('category "%s"', $category->slugPath), static function () use ($category): void {
                CategoryId::fromString($category->id);
                new CategoryName($category->name);
            });
        }

        $placeholderKey = ImageKey::fromString('originals/'.str_repeat('0', 64).'.jpg');
        foreach ($catalog->products as $product) {
            $this->check(\sprintf('product "%s"', $product->slug), static function () use ($product, $placeholderKey): void {
                new ProductName($product->name);
                new Slug($product->slug);
                new ShortDescription($product->shortDescription);
                TaxCategory::from($product->taxCategory);
                ProductCategories::create(
                    CategoryId::fromString($product->mainCategoryId),
                    array_map(CategoryId::fromString(...), $product->additionalCategoryIds),
                );
                foreach ($product->images as $position => $image) {
                    new ProductImage($placeholderKey, $image['alt'], $position);
                }
            });
        }
    }

    /**
     * @param \Closure(): void $validate
     */
    private function check(string $what, \Closure $validate): void
    {
        try {
            $validate();
        } catch (\InvalidArgumentException|\ValueError $e) {
            throw new \InvalidArgumentException(\sprintf('The demo %s is invalid: %s', $what, $e->getMessage()), 0, $e);
        }
    }
}
