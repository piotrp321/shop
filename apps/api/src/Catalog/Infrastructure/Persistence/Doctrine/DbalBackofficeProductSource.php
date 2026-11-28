<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\BackofficeProductSource;
use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\BackofficeProductList\CategoryList;
use App\Catalog\Infrastructure\Persistence\Doctrine\BackofficeProductList\Keyset;
use App\Catalog\Infrastructure\Persistence\Doctrine\BackofficeProductList\Like;
use App\Catalog\Infrastructure\Persistence\Doctrine\BackofficeProductList\PhraseSearch;
use App\Shared\Domain\BoundedCount;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalBackofficeProductSource implements BackofficeProductSource
{
    // The conditions are constant fragments joined by AND; input only ever reaches the statement as parameters.
    // The order and the keyset cursor are served by the (name, id) index, or by (updated_at, id) for the sort by the last
    // change: both directions of a sort read the same index, forwards or backwards, because the direction of the id
    // follows the one of the sorted column. The cursor is a row comparison in that direction, which keeps it an index
    // condition. A category matches when the product's main category or one of its additional categories is in the
    // subtree: the path of every category starts with the path of its ancestors (materialized path), so the subtree is a
    // prefix match on the path.
    //
    // The plans. A name term (ILIKE '%…%') is not left to the planner, which estimates it without knowing where in the
    // order of the sort the hits lie: PhraseSearch chooses the way by the work (a walk of the index of the sort with a
    // budget that grows by steps, then the candidates of a GIN index, the trigrams or, for a phrase without a word of
    // three characters, the bigrams of catalog_name_bigrams(name)), and the count of the first page goes the same way.
    // Without a term the filters stay with the planner, and so does a term together with the drafts or the products without
    // images, each of which has a partial index that holds only those few products: the drafts in the order of the sorts
    // ((name, id) and (updated_at, id) WHERE status = 'draft'), so the walk of it with the term as a filter reads at most all
    // the drafts; the products without images by id (WHERE images = '[]'), so the planner reads those few products from it
    // and sorts them, which costs by their number, not by the table (ProductsWithoutImagesPlanTest).
    // A category without a term is CategoryList: a branch per category of its subtree from the index (primary_category_id, sort key,
    // id) (the partial ones for the drafts and the products without images) and the products that are in it only by additional
    // categories; with a term the category is the condition below, checked on the rows of PhraseSearch (a walk and the candidates).
    private const string TERM = 'p.name ILIKE :pattern';
    // The exact predicate of the partial index catalog_product_without_images_idx (id) WHERE images = '[]'::jsonb, which keeps
    // the few products without images cheap to find, count and page among millions.
    private const string WITHOUT_IMAGES = "p.images = '[]'::jsonb";
    private const string CATEGORY = <<<'SQL'
        (
            p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
            OR EXISTS (
                SELECT 1 FROM catalog_product_category pc
                WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
            )
        )
        SQL;

    private PhraseSearch $phrases;
    private CategoryList $categories;

    /**
     * @param int $phraseBudget the rows of the index of the sort that the first walk of a phrase reads (each step after it reads ten times more)
     */
    public function __construct(private Connection $connection, private BoundedCounter $counter, int $phraseBudget = PhraseSearch::FIRST_BUDGET)
    {
        $this->phrases = new PhraseSearch($connection, $phraseBudget);
        $this->categories = new CategoryList($connection);
    }

    public function page(?string $term, ?CategoryPath $category, ?ProductStatus $status, ?ProductCursor $after, ProductSort $sort, int $limit, bool $withoutImages = false): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page limit must be at least 1.');
        }

        if (null !== $after && $after->sort !== $sort) {
            throw new \InvalidArgumentException('The cursor is of another sort.');
        }
        if (null !== $term && self::byPhrase($status, $withoutImages)) {
            [$conditions, $parameters] = self::otherFilters($category, $status, $withoutImages);

            return $this->phrases->page($term, $conditions, $parameters, $after, $sort, $limit);
        }

        if (null === $term && null !== $category) {
            [$conditions, $parameters] = self::otherFilters(null, $status, $withoutImages);

            return $this->categories->page(Like::escape($category->toString()).'%', $conditions, $parameters, $after, $sort, $limit);
        }

        [$conditions, $parameters] = self::filter($term, $category, $status, $withoutImages);
        $parameters['limit'] = $limit;
        $types = ['limit' => ParameterType::INTEGER];
        $direction = $sort->isDescending() ? 'DESC' : 'ASC';
        $column = $sort->byUpdatedAt() ? 'p.updated_at' : 'p.name';
        if (null !== $after) {
            [$condition, $values] = Keyset::after('p', $sort, $after);
            $conditions[] = $condition;
            $parameters += $values;
        }

        $sql = 'SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p'
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            ." ORDER BY {$column} {$direction}, p.id {$direction} LIMIT :limit";

        return array_map(static function (array $row): BackofficeProduct {
            \assert(\is_string($row['id']) && \is_string($row['name']) && \is_string($row['tax_class']) && \is_string($row['updated_at']));

            return new BackofficeProduct($row['id'], $row['name'], $row['tax_class'], new \DateTimeImmutable($row['updated_at']));
        }, $this->connection->executeQuery($sql, $parameters, $types)->fetchAllAssociative());
    }

    public function count(?string $term, ?CategoryPath $category, ?ProductStatus $status, bool $withoutImages = false, int $limit = BoundedCounter::DEFAULT_LIMIT): BoundedCount
    {
        if (null !== $term && self::byPhrase($status, $withoutImages)) {
            [$conditions, $parameters] = self::otherFilters($category, $status, $withoutImages);

            return $this->phrases->count($term, $conditions, $parameters, $limit);
        }

        if (null === $term && null !== $category) {
            [$conditions, $parameters] = self::otherFilters(null, $status, $withoutImages);

            return $this->categories->count(Like::escape($category->toString()).'%', $conditions, $parameters, $limit);
        }

        [$conditions, $parameters] = self::filter($term, $category, $status, $withoutImages);

        return $this->counter->count('catalog_product p', $conditions, $parameters, limit: $limit);
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
     * Whether a term is looked for by PhraseSearch: with the drafts or the products without images it stays a filter of the
     * statement, over the partial indexes of those.
     */
    private static function byPhrase(?ProductStatus $status, bool $withoutImages): bool
    {
        return ProductStatus::Draft !== $status && !$withoutImages;
    }

    /**
     * The conditions of the filters, the same for a page and for the count, without the cursor.
     *
     * @return array{list<string>, array<string, mixed>} the conditions and the values they bind
     */
    private static function filter(?string $term, ?CategoryPath $category, ?ProductStatus $status, bool $withoutImages): array
    {
        [$conditions, $parameters] = self::otherFilters($category, $status, $withoutImages);
        if (null !== $term) {
            array_unshift($conditions, self::TERM);
            $parameters['pattern'] = Like::contains($term);
        }

        return [$conditions, $parameters];
    }

    /**
     * The conditions of the filters other than the term.
     *
     * @return array{list<string>, array<string, mixed>} the conditions and the values they bind
     */
    private static function otherFilters(?CategoryPath $category, ?ProductStatus $status, bool $withoutImages): array
    {
        $conditions = [];
        $parameters = [];
        if (null !== $category) {
            $conditions[] = self::CATEGORY;
            $parameters['prefix'] = Like::escape($category->toString()).'%';
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
}
