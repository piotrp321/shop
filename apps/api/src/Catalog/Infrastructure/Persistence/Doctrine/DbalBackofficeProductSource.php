<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\BackofficeProductSource;
use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Product\ProductStatus;
use App\Shared\Domain\BoundedCount;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalBackofficeProductSource implements BackofficeProductSource
{
    // The conditions are constant fragments joined by AND; input only ever reaches the statement as parameters.
    // The name term (ILIKE '%…%') is served by the trigram index from 3 characters on; pg_trgm extracts no trigram from
    // a 2-character term, so those run as a scan of the (name, id) index with the term as a filter. The order and the
    // keyset cursor are served by the (name, id) index, or by (updated_at, id) for the sort by the last change: both
    // directions of a sort read the same index, forwards or backwards, because the direction of the id follows the
    // one of the sorted column. The cursor is a row comparison in that direction, which keeps it an index condition.
    // A category matches when the product's main category or one of its additional categories is in the subtree: the
    // path of every category starts with the path of its ancestors (materialized path), so the subtree is a prefix
    // match on the path.
    private const string TERM = 'p.name ILIKE :pattern';
    // The exact predicate of the partial index catalog_product_without_images_idx (id) WHERE images = '[]'::jsonb, which keeps
    // the few products without images cheap to find, count and page among millions.
    private const string WITHOUT_IMAGES = "p.images = '[]'::jsonb";
    private const string AFTER_NAME = '(p.name, p.id) %s (:after_value, CAST(:after_id AS uuid))';
    private const string AFTER_UPDATED_AT = '(p.updated_at, p.id) %s (CAST(:after_value AS timestamptz), CAST(:after_id AS uuid))';
    private const string CATEGORY = <<<'SQL'
        (
            p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
            OR EXISTS (
                SELECT 1 FROM catalog_product_category pc
                WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
            )
        )
        SQL;

    public function __construct(private Connection $connection, private BoundedCounter $counter)
    {
    }

    public function page(?string $term, ?CategoryPath $category, ?ProductStatus $status, ?ProductCursor $after, ProductSort $sort, int $limit, bool $withoutImages = false): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page limit must be at least 1.');
        }

        [$conditions, $parameters] = self::filter($term, $category, $status, $withoutImages);
        $parameters['limit'] = $limit;
        $types = ['limit' => ParameterType::INTEGER];
        $direction = $sort->isDescending() ? 'DESC' : 'ASC';
        $column = $sort->byUpdatedAt() ? 'p.updated_at' : 'p.name';
        if (null !== $after) {
            if ($after->sort !== $sort) {
                throw new \InvalidArgumentException('The cursor is of another sort.');
            }
            $conditions[] = \sprintf($sort->byUpdatedAt() ? self::AFTER_UPDATED_AT : self::AFTER_NAME, $sort->isDescending() ? '<' : '>');
            $parameters['after_value'] = $after->value instanceof \DateTimeImmutable ? $after->value->format('Y-m-d H:i:s.uP') : $after->value;
            $parameters['after_id'] = $after->id;
        }

        $sql = 'SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p'
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            ." ORDER BY {$column} {$direction}, p.id {$direction} LIMIT :limit";

        return array_map(static function (array $row): BackofficeProduct {
            \assert(\is_string($row['id']) && \is_string($row['name']) && \is_string($row['tax_class']) && \is_string($row['updated_at']));

            return new BackofficeProduct($row['id'], $row['name'], $row['tax_class'], new \DateTimeImmutable($row['updated_at']));
        }, $this->connection->executeQuery($sql, $parameters, $types)->fetchAllAssociative());
    }

    public function count(?string $term, ?CategoryPath $category, ?ProductStatus $status, bool $withoutImages = false): BoundedCount
    {
        [$conditions, $parameters] = self::filter($term, $category, $status, $withoutImages);

        return $this->counter->count('catalog_product p', $conditions, $parameters);
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
     * The conditions of the filters, the same for a page and for the count, without the cursor.
     *
     * @return array{list<string>, array<string, mixed>} the conditions and the values they bind
     */
    private static function filter(?string $term, ?CategoryPath $category, ?ProductStatus $status, bool $withoutImages): array
    {
        $conditions = [];
        $parameters = [];
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
        if ($withoutImages) {
            $conditions[] = self::WITHOUT_IMAGES;
        }

        return [$conditions, $parameters];
    }

    /**
     * Makes `%`, `_` and the escape character `\` (PostgreSQL's default) match themselves in LIKE and ILIKE.
     */
    private static function escapeLike(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
