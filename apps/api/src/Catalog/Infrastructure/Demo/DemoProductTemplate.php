<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Demo;

use App\Catalog\Domain\Product\VariantAxes;

/**
 * A product template of the demo definition: the products are the product of brands and models (the name never holds
 * a variant value); the variants of a product are the product of the values of its 0-2 variant axes. The optional
 * "description" (paragraphs) and "parameters" are those of the type; a model adds "parameters" of its own (one of the
 * same name replaces the one of the type) or has a "fullDescription" (paragraphs) that takes the place of its sentence
 * and of the paragraphs of the type.
 */
final readonly class DemoProductTemplate
{
    /**
     * @param list<string>                                                                                                                                  $additionalCategories slug paths
     * @param non-empty-list<string>                                                                                                                        $brands
     * @param non-empty-list<array{name: string, description: string, parameters: list<array{name: string, value: string}>, fullDescription: list<string>}> $models               fullDescription is empty for a model without its own description
     * @param list<string>                                                                                                                                  $variantAxes          names of the axes, none for a default variant
     * @param list<non-empty-list<string>>                                                                                                                  $axisValues           one list of values per axis, in the order of the axes
     * @param non-empty-list<array{file: string, alt: string}>                                                                                              $images               the pool of the template
     * @param list<array{brand: string, model: string, images: non-empty-list<array{file: string, alt: string}>}>                                           $productImages        the images of single products (brand + model), taken instead of the pool
     * @param list<string>                                                                                                                                  $description          the paragraphs of the type, none for a type without
     * @param list<array{name: string, value: string}>                                                                                                      $parameters           the parameters of the type in presentation order
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
        public array $productImages = [],
        public array $description = [],
        public array $parameters = [],
    ) {
    }

    /**
     * The own images of the product (brand + model) in file order, null when it takes the pool.
     *
     * @return non-empty-list<array{file: string, alt: string}>|null
     */
    public function imagesOf(string $brand, string $model): ?array
    {
        foreach ($this->productImages as $entry) {
            if ($entry['brand'] === $brand && $entry['model'] === $model) {
                return $entry['images'];
            }
        }

        return null;
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
                'parameters' => self::parameters($model, $where.' model'),
                'fullDescription' => self::paragraphs($model, 'fullDescription', $where.' model'),
            ];
        }
        $images = self::images($data, $where);

        \assert([] !== $models);

        [$variantAxes, $axisValues] = self::variantDefinition($data, $where);
        $brands = DemoCatalogDefinition::strings($data, 'brands', $where);

        return new self(
            DemoCatalogDefinition::string($data, 'category', $where),
            $additional,
            DemoCatalogDefinition::string($data, 'productType', $where),
            DemoCatalogDefinition::string($data, 'taxClass', $where),
            $brands,
            $models,
            $variantAxes,
            $axisValues,
            $images,
            self::productImages($data, $where, $brands, array_column($models, 'name')),
            self::paragraphs($data, 'description', $where),
            self::parameters($data, $where),
        );
    }

    /**
     * The optional paragraphs under $key: none when the key is missing, otherwise a non-empty list of non-empty texts.
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function paragraphs(array $data, string $key, string $where): array
    {
        if (!\array_key_exists($key, $data)) {
            return [];
        }

        return DemoCatalogDefinition::strings($data, $key, $where);
    }

    /**
     * The optional "parameters": a list of maps with a "name" and a "value". Whether the texts make valid parameters
     * is for the domain to say.
     *
     * @param array<mixed> $data
     *
     * @return list<array{name: string, value: string}>
     */
    private static function parameters(array $data, string $where): array
    {
        $parameters = [];
        foreach (DemoCatalogDefinition::list($data, 'parameters', $where, required: false) as $parameter) {
            if (!\is_array($parameter)) {
                throw new \InvalidArgumentException(\sprintf('A parameter of %s must be a map.', $where));
            }
            $parameters[] = [
                'name' => DemoCatalogDefinition::string($parameter, 'name', $where.' parameter'),
                'value' => DemoCatalogDefinition::string($parameter, 'value', $where.' parameter'),
            ];
        }

        return $parameters;
    }

    /**
     * @param array<mixed> $data
     *
     * @return non-empty-list<array{file: string, alt: string}>
     */
    private static function images(array $data, string $where): array
    {
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
        \assert([] !== $images);

        return $images;
    }

    /**
     * The optional "productImages": images of single products, each a brand and a model of this template.
     *
     * @param array<mixed>           $data
     * @param non-empty-list<string> $brands
     * @param list<string>           $modelNames
     *
     * @return list<array{brand: string, model: string, images: non-empty-list<array{file: string, alt: string}>}>
     */
    private static function productImages(array $data, string $where, array $brands, array $modelNames): array
    {
        $productImages = [];
        $seen = [];
        foreach (DemoCatalogDefinition::list($data, 'productImages', $where, required: false) as $entry) {
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException(\sprintf('A "productImages" entry of %s must be a map.', $where));
            }
            $entryWhere = $where.' productImages entry';
            $brand = DemoCatalogDefinition::string($entry, 'brand', $entryWhere);
            $model = DemoCatalogDefinition::string($entry, 'model', $entryWhere);
            if (!\in_array($brand, $brands, true)) {
                throw new \InvalidArgumentException(\sprintf('"productImages" of %s refers to brand "%s" that is not one of its brands.', $where, $brand));
            }
            if (!\in_array($model, $modelNames, true)) {
                throw new \InvalidArgumentException(\sprintf('"productImages" of %s refers to model "%s" that is not one of its models.', $where, $model));
            }
            $key = json_encode([$brand, $model], \JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(\sprintf('"productImages" of %s lists brand "%s" and model "%s" more than once.', $where, $brand, $model));
            }
            $seen[$key] = true;

            $productImages[] = ['brand' => $brand, 'model' => $model, 'images' => self::images($entry, $entryWhere)];
        }

        return $productImages;
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
