<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Application\Demo\DemoCategory;
use App\Catalog\Application\Demo\DemoProduct;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\VariantKey;
use App\Catalog\Domain\Product\VariantValues;
use Symfony\Component\Uid\Uuid;

/**
 * Expands the definition deterministically (no randomness) into categories and products.
 *
 * Products get a global 0-based expansion index: templates in file order, then brands and models. A product
 * is imageless when (index + 1) % imagelessEvery == 0; the others take pool[index % count(pool)] of their template.
 * The variants of a product are the product of the values of its axes in file order (the first axis varies slowest);
 * a product without axes has one default variant. Ids are UUID v5 in the definition's namespace: 'category:<slug path>',
 * 'product:<slug>' and 'variant:<variant key>' (<slug>/standard for the default variant). The key is the one of
 * Catalog's VariantKey, so a repeated key is rejected like a repeated slug.
 */
final readonly class DemoCatalogExpander
{
    /**
     * The photo paths are $imagesDir/<file>; whether the files exist is not checked here.
     *
     * @throws \InvalidArgumentException on a duplicated category path, product slug or variant key, or an unknown category path
     */
    public function expand(DemoCatalogDefinition $definition, string $imagesDir): DemoCatalog
    {
        $namespace = Uuid::fromString($definition->namespace);

        $categories = [];
        $idsByPath = [];
        foreach ($definition->categories as $root) {
            $this->addCategory($namespace, $root, null, '', $categories, $idsByPath);
        }

        $products = [];
        $slugs = [];
        $variantKeys = [];
        $index = 0;
        foreach ($definition->templates as $template) {
            $main = $this->categoryId($idsByPath, $template->category);
            $additional = array_map(fn (string $path): string => $this->categoryId($idsByPath, $path), $template->additionalCategories);

            foreach ($template->brands as $brand) {
                foreach ($template->models as $model) {
                    $name = \sprintf('%s %s %s', $template->productType, $brand, $model['name']);
                    $slug = Slug::slugify($name);
                    if (isset($slugs[$slug])) {
                        throw new \InvalidArgumentException(\sprintf('The product slug "%s" is produced more than once.', $slug));
                    }
                    $slugs[$slug] = true;

                    $variants = [];
                    foreach ($this->combinations($template->axisValues) as $values) {
                        $variantKey = $this->variantKey($slug, $values);
                        if (isset($variantKeys[$variantKey])) {
                            throw new \InvalidArgumentException(\sprintf('The variant key "%s" is produced more than once.', $variantKey));
                        }
                        $variantKeys[$variantKey] = true;
                        $variants[] = ['id' => Uuid::v5($namespace, 'variant:'.$variantKey)->toRfc4122(), 'values' => $values];
                    }

                    $products[] = new DemoProduct(
                        Uuid::v5($namespace, 'product:'.$slug)->toRfc4122(),
                        $name,
                        $slug,
                        $model['description'],
                        $main,
                        $additional,
                        $template->taxClass,
                        $this->images($template, $index, $definition->imagelessEvery),
                        $template->variantAxes,
                        $variants,
                    );
                    ++$index;
                }
            }
        }

        $photoPaths = [];
        foreach ($products as $product) {
            foreach ($product->images as $image) {
                $photoPaths[$image['file']] ??= rtrim($imagesDir, '/').'/'.$image['file'];
            }
        }

        return new DemoCatalog($categories, $products, $photoPaths);
    }

    /**
     * @param list<DemoCategory>    $categories
     * @param array<string, string> $idsByPath
     */
    private function addCategory(Uuid $namespace, DemoCategoryNode $node, ?string $parentId, string $parentPath, array &$categories, array &$idsByPath): void
    {
        $path = '' === $parentPath ? $node->slug : $parentPath.'/'.$node->slug;
        if (isset($idsByPath[$path])) {
            throw new \InvalidArgumentException(\sprintf('The category path "%s" is defined more than once.', $path));
        }

        $id = Uuid::v5($namespace, 'category:'.$path)->toRfc4122();
        $idsByPath[$path] = $id;
        $categories[] = new DemoCategory($id, $node->name, $node->slug, $parentId, $path);
        foreach ($node->children as $child) {
            $this->addCategory($namespace, $child, $id, $path, $categories, $idsByPath);
        }
    }

    /**
     * The product of the value lists, the last list varying fastest; a single empty combination without lists.
     *
     * @param list<non-empty-list<string>> $axisValues
     *
     * @return list<list<string>>
     */
    private function combinations(array $axisValues): array
    {
        $combinations = [[]];
        foreach ($axisValues as $values) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $next[] = [...$combination, $value];
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * @param list<string> $values
     */
    private function variantKey(string $slug, array $values): string
    {
        try {
            return VariantKey::of($slug, VariantValues::fromList($values));
        } catch (\DomainException $e) {
            throw new \InvalidArgumentException(\sprintf('The demo product "%s" has an invalid variant: %s', $slug, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, string> $idsByPath
     */
    private function categoryId(array $idsByPath, string $path): string
    {
        return $idsByPath[$path] ?? throw new \InvalidArgumentException(\sprintf('Unknown category path "%s".', $path));
    }

    /**
     * @return list<array{file: string, alt: string}>
     */
    private function images(DemoProductTemplate $template, int $index, int $imagelessEvery): array
    {
        if (0 === ($index + 1) % $imagelessEvery) {
            return [];
        }

        return [$template->images[$index % \count($template->images)]];
    }
}
