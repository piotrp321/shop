<?php

declare(strict_types=1);

namespace App\Catalog\Application\Demo;

/**
 * A product template of the demo definition: the products are the product of brands, models and colors.
 */
final readonly class DemoProductTemplate
{
    /**
     * @param list<string>                                             $additionalCategories slug paths
     * @param non-empty-list<string>                                   $brands
     * @param non-empty-list<array{name: string, description: string}> $models
     * @param non-empty-list<string>                                   $colors
     * @param non-empty-list<array{file: string, alt: string}>         $images               the pool of the template
     */
    public function __construct(
        public string $category,
        public array $additionalCategories,
        public string $productType,
        public string $taxCategory,
        public array $brands,
        public array $models,
        public array $colors,
        public array $images,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $where): self
    {
        $additional = [];
        foreach (DemoCatalogDefinition::list($data, 'additionalCategories', $where, required: false) as $path) {
            if (!\is_string($path) || '' === $path) {
                throw new \InvalidArgumentException(\sprintf('"additionalCategories" of %s must hold slug paths.', $where));
            }
            $additional[] = $path;
        }

        $models = [];
        foreach (DemoCatalogDefinition::list($data, 'models', $where) as $model) {
            if (!\is_array($model)) {
                throw new \InvalidArgumentException(\sprintf('A model of %s must be a map.', $where));
            }
            $models[] = [
                'name' => DemoCatalogDefinition::string($model, 'name', $where.' model'),
                'description' => DemoCatalogDefinition::string($model, 'description', $where.' model'),
            ];
        }
        $images = [];
        foreach (DemoCatalogDefinition::list($data, 'images', $where) as $image) {
            if (!\is_array($image)) {
                throw new \InvalidArgumentException(\sprintf('An image of %s must be a map.', $where));
            }
            $images[] = [
                'file' => DemoCatalogDefinition::string($image, 'file', $where.' image'),
                'alt' => DemoCatalogDefinition::string($image, 'alt', $where.' image'),
            ];
        }

        \assert([] !== $models && [] !== $images);

        return new self(
            DemoCatalogDefinition::string($data, 'category', $where),
            $additional,
            DemoCatalogDefinition::string($data, 'productType', $where),
            DemoCatalogDefinition::string($data, 'taxCategory', $where),
            DemoCatalogDefinition::strings($data, 'brands', $where),
            $models,
            DemoCatalogDefinition::strings($data, 'colors', $where),
            $images,
        );
    }
}
