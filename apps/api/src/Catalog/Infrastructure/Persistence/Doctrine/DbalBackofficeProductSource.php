<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\BackofficeProductSource;
use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Product\ProductStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalBackofficeProductSource implements BackofficeProductSource
{
    // The conditions are constant fragments joined by AND; input only ever reaches the statement as parameters.
    // The name term (ILIKE '%…%') is served by the trigram index from 3 characters on; pg_trgm extracts no trigram from
    // a 2-character term, so those run as a scan of the (name, id) index with the term as a filter. The order and the
    // keyset cursor are served by the (name, id) index. The cursor is a row comparison, which keeps it an index condition. A category matches when the product's
    // main category or one of its additional categories is in the subtree: the path of every category starts with
    // the path of its ancestors (materialized path), so the subtree is a prefix match on the path.
    private const string TERM = 'p.name ILIKE :pattern';
    private const string CURSOR = '(p.name, p.id) > (:after_name, CAST(:after_id AS uuid))';
    private const string CATEGORY = <<<'SQL'
        (
            p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
            OR EXISTS (
                SELECT 1 FROM catalog_product_category pc
                WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
            )
        )
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function page(?string $term, ?CategoryPath $category, ?ProductStatus $status, ?ProductCursor $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page limit must be at least 1.');
        }

        $conditions = [];
        $parameters = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $term) {
            $conditions[] = self::TERM;
            $parameters['pattern'] = '%'.self::escapeLike($term).'%';
        }
        if (null !== $category) {
            $conditions[] = self::CATEGORY;
            $parameters['prefix'] = self::escapeLike($category->toString()).'%';
        }
        if (null !== $status) {
            // The status is a value of the domain enum, never input, and goes into the statement as a literal: only
            // then can the planner prove the predicate of the partial index (name, id) WHERE status = 'draft' that
            // keeps the list of the few drafts cheap (a bound parameter could not use it in a generic plan).
            $conditions[] = "p.status = '".$status->value."'";
        }
        if (null !== $after) {
            $conditions[] = self::CURSOR;
            $parameters['after_name'] = $after->name;
            $parameters['after_id'] = $after->id;
        }

        $sql = 'SELECT p.id, p.name, p.tax_class FROM catalog_product p'
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            .' ORDER BY p.name, p.id LIMIT :limit';

        return array_map(static function (array $row): BackofficeProduct {
            \assert(\is_string($row['id']) && \is_string($row['name']) && \is_string($row['tax_class']));

            return new BackofficeProduct($row['id'], $row['name'], $row['tax_class']);
        }, $this->connection->executeQuery($sql, $parameters, $types)->fetchAllAssociative());
    }

    public function summaries(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // One row per product: the main category by its key, the number of variants by the index of the product, the
        // first image of the JSON list.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.id, p.slug, p.status, p.updated_at, c.id AS category_id, c.name AS category_name,
                (SELECT COUNT(*) FROM catalog_product_variant v WHERE v.product_id = p.id) AS variant_count,
                p.images -> 0 ->> 'key' AS image_key, p.images -> 0 ->> 'alt' AS image_alt
             FROM catalog_product p
             JOIN catalog_category c ON c.id = p.primary_category_id
             WHERE p.id = ANY(CAST(:ids AS uuid[]))",
            ['ids' => '{'.implode(',', $ids).'}'],
        );

        $summaries = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['id']) && \is_string($row['slug']) && \is_string($row['status']) && \is_string($row['updated_at']));
            \assert(\is_string($row['category_id']) && \is_string($row['category_name']) && \is_int($row['variant_count']));
            \assert(null === $row['image_key'] || \is_string($row['image_key']));
            \assert(null === $row['image_alt'] || \is_string($row['image_alt']));
            $summaries[$row['id']] = [
                'slug' => $row['slug'],
                'status' => $row['status'],
                'mainCategoryId' => $row['category_id'],
                'mainCategoryName' => $row['category_name'],
                'variantCount' => $row['variant_count'],
                'imageKey' => $row['image_key'],
                'imageAlt' => $row['image_alt'],
                'updatedAt' => new \DateTimeImmutable($row['updated_at']),
            ];
        }

        return $summaries;
    }

    /**
     * Makes `%`, `_` and the escape character `\` (PostgreSQL's default) match themselves in LIKE and ILIKE.
     */
    private static function escapeLike(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
