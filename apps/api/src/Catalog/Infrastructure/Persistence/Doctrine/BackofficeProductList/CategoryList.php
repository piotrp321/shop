<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\BackofficeProductList;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
use App\Shared\Domain\BoundedCount;
use Doctrine\DBAL\Connection;

/**
 * The pages and the count of the product list of a category (its subtree) without a phrase. The result is the one of the
 * statement `WHERE main category IN subtree OR EXISTS (additional category IN subtree) ORDER BY sort LIMIT n`, which the planner
 * answers by a walk of the index of the sort that filters the category (the whole table for a small category or one at the end of
 * the order); the category is the stream of its parts instead:
 *  - one branch per category of the subtree, the products that have it as the main category, read in the order of the sort from
 *    the index (primary_category_id, name, id) or (primary_category_id, updated_at, id), a page of rows (and one more) each; for
 *    the drafts and the products without images the same keys are indexes of their own (the partial ones), so a branch reads
 *    only those;
 *  - the products that are in the subtree only by additional categories: the members of the table of the memberships (it has no
 *    key of the sort), joined to their products, without those whose main category is in the subtree (the branches have them), and
 *    one row per product (DISTINCT: the domain allows up to ten additional categories, so a product may be in the subtree
 *    several times), as a set of its own (MATERIALIZED, or the planner takes the walk of the (name, id) index with the membership
 *    as a filter); the cost is linear in these members.
 * The branches are disjoint, so the rows are put together and sorted (the categories times a page of them, and the page of the
 * additional ones), and the first rows are the page. The count is the rows of both parts up to the limit, and goes by the same
 * conditions.
 */
final readonly class CategoryList
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string               $prefix     the path of the category, escaped for LIKE, with the trailing `%`
     * @param list<string>         $conditions the other filters on `p` (status, without images), constant fragments
     * @param array<string, mixed> $parameters the values they bind
     *
     * @return list<BackofficeProduct> at most $limit products, in the order of the sort, the ones after the cursor
     */
    public function page(string $prefix, array $conditions, array $parameters, ?ProductCursor $after, ProductSort $sort, int $limit): array
    {
        $column = $sort->byUpdatedAt() ? 'updated_at' : 'name';
        $direction = $sort->isDescending() ? 'DESC' : 'ASC';
        $values = $parameters + ['prefix' => $prefix];
        if (null !== $after) {
            [$cursor, $cursorValues] = Keyset::after('p', $sort, $after);
            $conditions[] = $cursor;
            $values += $cursorValues;
        }
        $where = [] === $conditions ? '' : ' AND '.implode(' AND ', $conditions);
        $order = "ORDER BY {$column} {$direction}, id {$direction} LIMIT {$limit}";

        $sql = 'WITH additional AS MATERIALIZED ('.self::additionalMembers('p.id, p.name, p.tax_class, p.updated_at', $where).') '
            .'SELECT u.id, u.name, u.tax_class, u.updated_at FROM ('
            .'SELECT m.id, m.name, m.tax_class, m.updated_at FROM catalog_category c CROSS JOIN LATERAL ('
            ."SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.primary_category_id = c.id{$where} ORDER BY p.{$column} {$direction}, p.id {$direction} LIMIT {$limit}"
            .') m WHERE c.path LIKE :prefix '
            .'UNION ALL '
            ."SELECT a.id, a.name, a.tax_class, a.updated_at FROM (SELECT * FROM additional {$order}) a"
            .") u ORDER BY u.{$column} {$direction}, u.id {$direction} LIMIT {$limit}";

        return array_map(static function (array $row): BackofficeProduct {
            \assert(\is_string($row['id']) && \is_string($row['name']) && \is_string($row['tax_class']) && \is_string($row['updated_at']));

            return new BackofficeProduct($row['id'], $row['name'], $row['tax_class'], new \DateTimeImmutable($row['updated_at']));
        }, $this->connection->fetchAllAssociative($sql, $values));
    }

    /**
     * @param string               $prefix     as in page()
     * @param list<string>         $conditions as in page()
     * @param array<string, mixed> $parameters as in page()
     */
    public function count(string $prefix, array $conditions, array $parameters, int $limit): BoundedCount
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit of a count must be at least 1.');
        }
        $where = [] === $conditions ? '' : ' AND '.implode(' AND ', $conditions);
        // With the status or without images the branch is ordered by the key of the partial index of the category, which makes it the one the planner
        // takes (the partial index of the products without images by id is the other one that holds those products, and reads all of them).
        $counted = [] === $conditions ? '' : ' ORDER BY p.name, p.id';
        $sql = 'WITH additional AS MATERIALIZED ('.self::additionalMembers('p.id', $where).') '
            .'SELECT count(*) FROM (SELECT 1 FROM ('
            ."SELECT 1 FROM catalog_category c CROSS JOIN LATERAL (SELECT 1 FROM catalog_product p WHERE p.primary_category_id = c.id{$where}{$counted} LIMIT ".($limit + 1).') m WHERE c.path LIKE :prefix '
            .'UNION ALL '
            .'SELECT 1 FROM additional'
            .') u LIMIT '.($limit + 1).') bounded';
        $rows = $this->connection->fetchOne($sql, $parameters + ['prefix' => $prefix]);
        \assert(\is_int($rows));

        return BoundedCount::ofRowsRead($rows, $limit);
    }

    /**
     * The products that are in the subtree by an additional category and not by the main one, one row each (DISTINCT: a product may
     * be in the subtree by several of them). Cost linear in the memberships of the subtree: the planner takes the lookup of the
     * product of each of them by its key or, when they are a fair part of the table, a hash join with a scan of the products; the
     * members whose main category is in the subtree are most of them, and are discarded after the lookup (a copy of the main
     * category in the table of the memberships would spare it: decided after the measurement on `server`).
     *
     * @param string $columns the columns of `p` to return
     * @param string $where   the other conditions on `p` (each with the leading AND)
     */
    private static function additionalMembers(string $columns, string $where): string
    {
        return "SELECT DISTINCT {$columns} FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id "
            .'WHERE pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix) '
            .'AND p.primary_category_id NOT IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)'.$where;
    }
}
