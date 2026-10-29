<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Application\Demo\DemoCategory;
use App\Catalog\Application\Demo\DemoProduct;
use Symfony\Component\Uid\Uuid;

/**
 * Expands the definition deterministically (no randomness) into categories and products.
 *
 * Products get a global 0-based expansion index: templates in file order, then brands, models and colors. A product
 * is imageless when (index + 1) % imagelessEvery == 0; the others take pool[index % count(pool)] of their template.
 * Ids are UUID v5 of the slug in the definition's namespace: 'category:<slug path>' and 'product:<slug>'.
 */
final readonly class DemoCatalogExpander
{
    private const array TRANSLITERATION = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
    ];

    /**
     * @throws \InvalidArgumentException on a duplicated category path or product slug, or an unknown category path
     */
    public function expand(DemoCatalogDefinition $definition): DemoCatalog
    {
        $namespace = Uuid::fromString($definition->namespace);

        $categories = [];
        $idsByPath = [];
        foreach ($definition->categories as $root) {
            $this->addCategory($namespace, $root, null, '', $categories, $idsByPath);
        }

        $products = [];
        $slugs = [];
        $index = 0;
        foreach ($definition->templates as $template) {
            $main = $this->categoryId($idsByPath, $template->category);
            $additional = array_map(fn (string $path): string => $this->categoryId($idsByPath, $path), $template->additionalCategories);

            foreach ($template->brands as $brand) {
                foreach ($template->models as $model) {
                    foreach ($template->colors as $color) {
                        $name = \sprintf('%s %s %s %s', $template->productType, $brand, $model['name'], $color);
                        $slug = $this->slugify($name);
                        if (isset($slugs[$slug])) {
                            throw new \InvalidArgumentException(\sprintf('The product slug "%s" is produced more than once.', $slug));
                        }
                        $slugs[$slug] = true;

                        $products[] = new DemoProduct(
                            Uuid::v5($namespace, 'product:'.$slug)->toRfc4122(),
                            $name,
                            $slug,
                            $model['description'],
                            $main,
                            $additional,
                            $template->taxCategory,
                            $this->images($template, $index, $definition->imagelessEvery),
                        );
                        ++$index;
                    }
                }
            }
        }

        return new DemoCatalog($categories, $products);
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
        $categories[] = new DemoCategory($id, $node->name, $parentId, $path);
        foreach ($node->children as $child) {
            $this->addCategory($namespace, $child, $id, $path, $categories, $idsByPath);
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

    private function slugify(string $name): string
    {
        $ascii = strtr(mb_strtolower($name), self::TRANSLITERATION);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    }
}
