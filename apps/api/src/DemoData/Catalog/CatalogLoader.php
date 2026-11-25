<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

use App\DemoData\Copy\CopyWriter;
use App\DemoData\Copy\Sql;
use App\DemoData\Profile\RunContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Step 1 of the generator: the categories of the tail and the products with their variants and category links, written
 * with COPY (no aggregates, no events, no outbox; the search index and the caches are refreshed by the end of
 * bin/generate-demo).
 *
 * Tables written: catalog_category, catalog_product, catalog_product_variant, catalog_product_category. The SKUs are
 * unique by construction (VariantCodes), so the unique index of the column stays in place while the rows are loaded.
 */
final readonly class CatalogLoader
{
    public function __construct(
        private Connection $connection,
        private CopyWriter $copy,
        private Dictionaries $dictionaries,
    ) {
    }

    /**
     * Writes the categories that the table does not have yet; a department of the seed catalog (found by its slug among
     * the root categories) is reused. Running it again writes nothing.
     *
     * @return array{created: int, existing: int}
     */
    public function prepareCategories(RunContext $context): array
    {
        $slugs = $this->dictionaries->departmentSlugs();
        /** @var array<string, string> $roots */
        $roots = $this->connection->fetchAllKeyValue('SELECT slug, id FROM catalog_category WHERE parent_id IS NULL AND slug IN (?)', [$slugs], [ArrayParameterType::STRING]);
        $specs = CategoryTree::build($this->dictionaries, $context->namespace, $roots);

        $ids = array_map(static fn (CategorySpec $spec): string => $spec->id, $specs);
        /** @var list<string> $present */
        $present = $this->connection->fetchFirstColumn('SELECT id FROM catalog_category WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);
        $present = array_flip($present);

        $missing = array_values(array_filter($specs, static fn (CategorySpec $spec): bool => !isset($present[$spec->id])));
        $this->connection->transactional(function () use ($missing): void {
            $this->copy->relaxDurability();
            $this->copy->copy(
                'catalog_category',
                ['id', 'name', 'slug', 'parent_id', 'path', 'position', 'version'],
                array_map(static fn (CategorySpec $spec): array => [$spec->id, $spec->name, $spec->slug, $spec->parentId, $spec->path, $spec->position, 1], $missing),
            );
        });

        return ['created' => \count($missing), 'existing' => \count($specs) - \count($missing)];
    }

    /**
     * Whether every leaf category that the products point at is in the table.
     */
    public function categoriesArePrepared(RunContext $context): bool
    {
        $ids = array_map(static fn (string $path): string => CategoryTree::leafId($context->namespace, $path), $this->dictionaries->leafPaths());
        $found = Sql::int($this->connection->fetchOne('SELECT count(*) FROM catalog_category WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]));

        return $found === \count($ids);
    }

    /**
     * Writes the parts `worker`, `worker + workers`, `worker + 2 * workers`, … of the products, each in a transaction of
     * its own. The parts do not depend on the number of workers.
     *
     * @param \Closure(int, int, int): void|null $progress called after each part with the part, its products and variants
     *
     * @return array{parts: int, products: int, variants: int}
     */
    public function loadProducts(RunContext $context, int $worker, int $workers, ?\Closure $progress = null): array
    {
        $generator = new ProductGenerator($this->dictionaries, $context->namespace, $context->anchor, $context->profile->products, $context->partSize);
        $totals = ['parts' => 0, 'products' => 0, 'variants' => 0];

        for ($part = $worker; $part < $context->productParts(); $part += $workers) {
            [$products, $variants] = $this->connection->transactional(fn (): array => $this->loadPart($generator, $part));
            ++$totals['parts'];
            $totals['products'] += $products;
            $totals['variants'] += $variants;
            if (null !== $progress) {
                $progress($part, $products, $variants);
            }
        }

        return $totals;
    }

    /**
     * @return array{int, int} the products and the variants written
     */
    private function loadPart(ProductGenerator $generator, int $part): array
    {
        $this->copy->relaxDurability();

        $specs = iterator_to_array($generator->part($part), false);
        $products = $this->copy->copy(
            'catalog_product',
            ['id', 'slug', 'name', 'short_description', 'brand', 'description', 'parameters', 'primary_category_id', 'tax_class', 'images', 'variant_axes', 'status', 'first_published_at', 'updated_by', 'updated_at', 'version'],
            $this->productRows($specs),
        );
        $variants = $this->copy->copy(
            'catalog_product_variant',
            ['id', 'product_id', 'position', 'option_values', 'values_key', 'sku', 'ean'],
            $this->variantRows($specs),
        );
        $this->copy->copy('catalog_product_category', ['product_id', 'category_id'], $this->linkRows($specs));

        return [$products, $variants];
    }

    /**
     * @param list<ProductSpec> $specs
     *
     * @return \Generator<int, list<mixed>>
     */
    private function productRows(array $specs): \Generator
    {
        foreach ($specs as $spec) {
            yield [
                $spec->id,
                $spec->slug,
                $spec->name,
                $spec->shortDescription,
                $spec->brand,
                $spec->description,
                json_encode($spec->parameters, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                $spec->primaryCategoryId,
                $spec->taxClass,
                '[]',
                json_encode($spec->axes, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                $spec->status,
                $spec->firstPublishedAt,
                null,
                $spec->updatedAt,
                1,
            ];
        }
    }

    /**
     * @param list<ProductSpec> $specs
     *
     * @return \Generator<int, list<mixed>>
     */
    private function variantRows(array $specs): \Generator
    {
        foreach ($specs as $spec) {
            foreach ($spec->variants as $variant) {
                yield [$variant->id, $spec->id, $variant->position, json_encode($variant->values, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), $variant->valuesKey, $variant->sku, $variant->ean];
            }
        }
    }

    /**
     * @param list<ProductSpec> $specs
     *
     * @return \Generator<int, list<mixed>>
     */
    private function linkRows(array $specs): \Generator
    {
        foreach ($specs as $spec) {
            foreach ($spec->additionalCategoryIds as $categoryId) {
                yield [$spec->id, $categoryId];
            }
        }
    }
}
