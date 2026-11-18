<?php

declare(strict_types=1);

namespace App\DemoData\Verify;

use App\DemoData\Catalog\CategoryTree;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Copy\Sql;
use App\DemoData\Profile\RunContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The catalog checks of demo-data:verify: a published product has a variant, the counts of the tables are the ones of
 * the profile, and the slugs of the tail are unique and different from the slugs of fixtures/catalog.yaml. Returns the
 * violations; none means the data is consistent.
 */
final readonly class CatalogVerifier
{
    public function __construct(
        private Connection $connection,
        private Dictionaries $dictionaries,
        private SeedCatalog $seed,
    ) {
    }

    /**
     * @return list<string>
     */
    public function violations(RunContext $context): array
    {
        $seedSlugs = array_keys($this->seed->products());

        return [
            ...$this->publishedWithoutVariant(),
            ...$this->categories($context),
            ...$this->counts($context, $seedSlugs),
            ...$this->slugs($context, $seedSlugs),
            ...$this->seedSlugsTakenByOthers(),
        ];
    }

    /**
     * @return list<string>
     */
    private function publishedWithoutVariant(): array
    {
        /** @var list<string> $slugs */
        $slugs = $this->connection->fetchFirstColumn(
            "SELECT p.slug FROM catalog_product p WHERE p.status = 'published' AND NOT EXISTS (SELECT 1 FROM catalog_product_variant v WHERE v.product_id = p.id) ORDER BY p.slug LIMIT ".(Listing::SHOWN + 1),
        );

        return Listing::of('A published product has no variant', $slugs);
    }

    /**
     * @return list<string>
     */
    private function categories(RunContext $context): array
    {
        $ids = array_map(static fn ($spec): string => $spec->id, CategoryTree::build($this->dictionaries, $context->namespace));
        $found = Sql::int($this->connection->fetchOne('SELECT count(*) FROM catalog_category WHERE id IN (?) OR (parent_id IS NULL AND slug IN (?))', [$ids, $this->dictionaries->departmentSlugs()], [ArrayParameterType::STRING, ArrayParameterType::STRING]));

        return $found < \count($ids) ? [\sprintf('The category tree of the generator is incomplete: %d of %d categories found.', $found, \count($ids))] : [];
    }

    /**
     * @param list<string> $seedSlugs
     *
     * @return list<string>
     */
    private function counts(RunContext $context, array $seedSlugs): array
    {
        $generated = $this->generatedProducts($seedSlugs);
        if ($generated === $context->profile->products) {
            return [];
        }

        return [\sprintf('The profile "%s" has %d products of the generator, the table has %d.', $context->profile->name, $context->profile->products, $generated)];
    }

    /**
     * @param list<string> $seedSlugs
     *
     * @return list<string>
     */
    private function slugs(RunContext $context, array $seedSlugs): array
    {
        // A product of the tail ends its slug with the 1-based number of the product; the numbers are all different and
        // go from 1 to the number of products of the profile.
        $row = $this->connection->fetchAssociative(
            "SELECT count(*) AS products, count(DISTINCT substring(slug from '-([0-9]+)\$')) AS numbers, count(*) FILTER (WHERE slug !~ '-[0-9]+\$') AS unnumbered, coalesce(max(substring(slug from '-([0-9]+)\$')::int) FILTER (WHERE slug ~ '-[0-9]+\$'), 0) AS highest FROM catalog_product WHERE NOT (slug = ANY(?::text[]))",
            [Sql::textArray($seedSlugs)],
        );
        if (false === $row) {
            return [];
        }

        $violations = [];
        if (Sql::int($row['unnumbered']) > 0) {
            $violations[] = \sprintf('%d products of the tail have a slug without the number of the product.', Sql::int($row['unnumbered']));
        }
        if (Sql::int($row['numbers']) !== Sql::int($row['products']) - Sql::int($row['unnumbered'])) {
            $violations[] = 'Slugs of the tail repeat a product number, so they are not unique by construction.';
        }
        if (Sql::int($row['highest']) > $context->profile->products) {
            $violations[] = \sprintf('A slug of the tail has the number %d, above the %d products of the profile.', Sql::int($row['highest']), $context->profile->products);
        }

        return $violations;
    }

    /**
     * A product of the seed catalog has the id the seed gives it; another product with its slug means that a generated
     * slug collided with the seed.
     *
     * @return list<string>
     */
    private function seedSlugsTakenByOthers(): array
    {
        $seed = $this->seed->products();
        /** @var array<string, string> $stored */
        $stored = $this->connection->fetchAllKeyValue('SELECT slug, id FROM catalog_product WHERE slug IN (?)', [array_keys($seed)], [ArrayParameterType::STRING]);

        $taken = [];
        foreach ($stored as $slug => $id) {
            if ($seed[$slug] !== $id) {
                $taken[] = $slug;
            }
        }
        sort($taken);

        return Listing::of('The slug of a catalog.yaml product belongs to another product', $taken);
    }

    /**
     * @param list<string> $seedSlugs
     */
    private function generatedProducts(array $seedSlugs): int
    {
        return Sql::int($this->connection->fetchOne('SELECT count(*) FROM catalog_product WHERE NOT (slug = ANY(?::text[]))', [Sql::textArray($seedSlugs)]));
    }
}
