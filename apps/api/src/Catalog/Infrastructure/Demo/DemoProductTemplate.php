<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Demo;

use App\Catalog\Domain\Product\VariantAxes;

/**
 * A product template of the demo definition: the products are the product of brands and models (the name never holds
 * a variant value); the variants of a product are the product of the values of its 0-2 variant axes.
 */
final readonly class DemoProductTemplate
{
    /**
     * @param list<string>                                             $additionalCategories slug paths
     * @param non-empty-list<string>                                   $brands
     * @param non-empty-list<array{name: string, description: string}> $models
     * @param list<string>                                             $variantAxes          names of the axes, none for a default variant
     * @param list<non-empty-list<string>>                             $axisValues           one list of values per axis, in the order of the axes
     * @param non-empty-list<array{file: string, alt: string}>         $images               the pool of the template
     */
    public function __construct(
        public string $category,
        public array $additionalCategories,
        public string $productType,
        public string $taxClass,
        public array $brands,
        public array $models,
        public array $variantAxes,
        public array $axisValues,
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

        [$variantAxes, $axisValues] = self::variantDefinition($data, $where);

        return new self(
            DemoCatalogDefinition::string($data, 'category', $where),
            $additional,
            DemoCatalogDefinition::string($data, 'productType', $where),
            DemoCatalogDefinition::string($data, 'taxClass', $where),
            DemoCatalogDefinition::strings($data, 'brands', $where),
            $models,
            $variantAxes,
            $axisValues,
            $images,
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{list<string>, list<non-empty-list<string>>}
     */
    private static function variantDefinition(array $data, string $where): array
    {
        $axes = [];
        foreach (DemoCatalogDefinition::list($data, 'variantAxes', $where, required: false) as $axis) {
            if (!\is_string($axis) || '' === $axis) {
                throw new \InvalidArgumentException(\sprintf('"variantAxes" of %s must hold non-empty strings.', $where));
            }
            $axes[] = $axis;
        }
        if (\count($axes) > VariantAxes::MAX_AXES) {
            throw new \InvalidArgumentException(\sprintf('"variantAxes" of %s must hold at most %d names.', $where, VariantAxes::MAX_AXES));
        }

        $axisValues = [];
        foreach (DemoCatalogDefinition::list($data, 'axisValues', $where, required: false) as $values) {
            if (!\is_array($values)) {
                throw new \InvalidArgumentException(\sprintf('"axisValues" of %s must hold lists of values.', $where));
            }
            $axisValues[] = DemoCatalogDefinition::strings(['axisValues' => $values], 'axisValues', $where);
        }
        if (\count($axisValues) !== \count($axes)) {
            throw new \InvalidArgumentException(\sprintf('"axisValues" of %s must hold one list per variant axis (%d axes, %d lists).', $where, \count($axes), \count($axisValues)));
        }

        return [$axes, $axisValues];
    }
}
