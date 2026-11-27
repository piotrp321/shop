<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\BackofficeProductList;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
use App\Shared\Domain\BoundedCount;
use Doctrine\DBAL\Connection;

/**
 * The pages and the count of the product list for a phrase in the name, with the way to the rows chosen by the work it
 * takes, not by the planner's estimate of a `name ILIKE` it cannot make (it knows neither where in the order of the sort
 * the hits lie nor how many names a phrase without a trigram matches). The result is the one of the statement
 * `WHERE name ILIKE … ORDER BY sort LIMIT n`; only the way to it differs.
 *
 * A page is found in steps i = 0, 1, 2, … with the budget B(i) = firstBudget · 10^i:
 *  1. the walk: the first B(i) rows of the index of the sort (after the cursor) are checked for the phrase, in the order
 *     of the sort, and the walk stops at the page. It reads at most B(i) rows from the index, whatever the table size,
 *     and finds the page when the hits are dense in the prefix of the order (a phrase in many names);
 *  2. when the page is not full, the candidates: the names that the GIN index of the route gives for the phrase (the
 *     trigrams, or for a phrase without a word of three characters the bigrams), sorted, cut at C(i) = B(i) / 2 rows.
 *     When they are fewer than that, they are all the hits and the page is their start: the cost is linear in the hits.
 *     Otherwise the hits are many, and the step i + 1 walks ten times as far.
 * So the work is about the smaller of the position of the page in the order of the sort (a frequent phrase whose hits
 * are scattered, or start early in the order) and the number of the hits (a rare phrase, or one that lies at the end of
 * the order), up to a factor that does not depend on the size of the table. It ends because a walk longer than the table
 * is the whole answer, and the cap of the candidates grows with it. Another filter (category, published) is checked on
 * the rows of the walk and of the candidates, and does not change the way.
 *
 * The count of the first page walks the first budget (a phrase in more than half of the names has the limit within it),
 * and otherwise counts the candidates of the route up to the limit. It is not a BoundedCounter: that one has a single
 * statement, and the count follows the way of the page.
 *
 * The planner is steered in one place, the two statements of the candidates (the page and the count), under the
 * exception D10 of docs/features/admin-queries/spec.md: they are read with only bitmap scans allowed
 * (enable_seqscan, enable_indexscan and enable_indexonlyscan off), so that the GIN index is the way to them. The
 * candidates are a MATERIALIZED CTE read lazily up to a cap. The planner plans the CTE for all its rows and takes a phrase
 * for a dense one (the estimate of an ILIKE knows neither the frequency of the phrase nor where its hits lie in the heap),
 * so it chooses a sequential scan, which reads the table up to the cap-th hit: the table whole, when the hits are
 * at the end of the heap (observed in the plan test on 100 000 products: a Seq Scan estimated at 22 222 rows for a
 * phrase whose hits lie after the first 60% of the table, 229 809 rows read by a page against the bound of 115 406
 * rows). What was considered:
 *  - another form of the CTE (not materialized, a subquery with the LIMIT inside): the planner then plans for the LIMIT,
 *    taking the same estimate of the density as true, and stops after the cap-th hit only if the hits are where the
 *    estimate puts them (not run: it rests on the same estimate);
 *  - the choice by the estimate (EXPLAIN first, or the statistics): the same flawed estimate, and a trip more;
 *  - the candidates by the construction of the statement (a join, ctid, a function): GIN can be made the only access
 *    path by hiding the condition from the other indexes (the bigrams route does it for the trigram index, see
 *    candidateConditions()), but nothing in a statement hides a table from a sequential scan (not run);
 *  - plan_cache_mode: it chooses between the custom and the generic plan of a prepared statement and leaves the cost
 *    model, so the estimate, as it is (reasoning, not run);
 *  - a function with a SET clause that runs the statement (one trip, the settings restored by PostgreSQL): it needs a
 *    function of dynamic SQL in the schema that returns rows of any shape, kept in step with the text of the statement
 *    (not run, judged worse than the trips below).
 * The settings are local: they are set with set_config(…, true) (the effect of SET LOCAL) inside a transaction of their
 * own, a savepoint when the caller has a transaction, which is rolled back whatever happens (nothing is written), and
 * PostgreSQL takes the settings back with it, in the caller's transaction too. Four trips: begin, the settings (one
 * statement), the read, the rollback. They cannot be fewer: the planner reads the settings when it plans, so they cannot
 * be in the statement that they are for (nor can a transaction block be opened and the read sent in one statement with
 * bound parameters: the extended protocol runs a single statement). Nothing of the configuration of the planner of the
 * server and no timeout is changed.
 */
final readonly class PhraseSearch
{
    /** Rows of the index of the sort that the first walk reads; the pace of the candidates (their cap is half of it) is the one of the cost per row measured in PERF-7-1 (about 0,7 µs a row of a walk, 5 µs a candidate). */
    public const int FIRST_BUDGET = 20_000;
    private const int GROWTH = 10;
    private const string BITMAP_SCANS_ONLY = "SELECT set_config('enable_seqscan', 'off', true), set_config('enable_indexscan', 'off', true), set_config('enable_indexonlyscan', 'off', true)";

    public function __construct(private Connection $connection, private int $firstBudget = self::FIRST_BUDGET)
    {
        if ($firstBudget < 1) {
            throw new \InvalidArgumentException('The budget of the first walk must be at least 1.');
        }
    }

    /**
     * @param list<string>         $conditions conditions of the other filters on `p`, constant fragments
     * @param array<string, mixed> $parameters the values they bind
     *
     * @return list<BackofficeProduct> at most $limit products, in the order of the sort, the ones after the cursor
     */
    public function page(string $term, array $conditions, array $parameters, ?ProductCursor $after, ProductSort $sort, int $limit): array
    {
        $route = PhraseRoute::of($term);
        $values = $parameters + ['pattern' => Like::contains($term)];
        $keyset = null;
        if (null !== $after) {
            $keyset = ['w0' => Keyset::after('w0', $sort, $after), 'p' => Keyset::after('p', $sort, $after)];
            $values += $keyset['p'][1];
        }

        for ($budget = $this->firstBudget;; $budget *= self::GROWTH) {
            $products = $this->walk($conditions, $values, $keyset['w0'][0] ?? null, $sort, $limit, $budget);
            if (\count($products) >= $limit) {
                return $products;
            }

            [$products, $capped] = $this->candidates($route, $term, $conditions, $values, $keyset['p'][0] ?? null, $sort, $limit, max(1, intdiv($budget, 2)));
            if (!$capped) {
                return $products;
            }
        }
    }

    /**
     * @param list<string>         $conditions as in page()
     * @param array<string, mixed> $parameters as in page()
     */
    public function count(string $term, array $conditions, array $parameters, int $limit): BoundedCount
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit of a count must be at least 1.');
        }
        $route = PhraseRoute::of($term);
        $values = $parameters + ['pattern' => Like::contains($term)];
        $others = [] === $conditions ? '' : ' AND '.implode(' AND ', $conditions);

        $walked = $this->connection->fetchOne(
            'SELECT count(*) FROM (SELECT 1 FROM (SELECT w0.id, w0.name FROM catalog_product w0 ORDER BY w0.name, w0.id LIMIT '.$this->firstBudget.') w'
            .([] === $conditions ? '' : ' JOIN catalog_product p ON p.id = w.id')
            .' WHERE w.name ILIKE :pattern'.$others.' LIMIT '.($limit + 1).') bounded',
            $values,
        );
        \assert(\is_int($walked));
        if ($walked > $limit) {
            return BoundedCount::ofRowsRead($walked, $limit);
        }

        $where = $this->candidateConditions($route, $conditions, null);
        $candidates = $this->bitmapScansOnly(fn (): mixed => $this->connection->fetchOne(
            'WITH m AS MATERIALIZED (SELECT 1 FROM catalog_product p WHERE '.$where.') SELECT count(*) FROM (SELECT * FROM m LIMIT '.($limit + 1).') bounded',
            $values + ('' === $route->candidateCondition() ? [] : ['term' => $term]),
        ));
        \assert(\is_int($candidates));

        return BoundedCount::ofRowsRead($candidates, $limit);
    }

    /**
     * @param list<string>         $conditions
     * @param array<string, mixed> $values
     *
     * @return list<BackofficeProduct>
     */
    private function walk(array $conditions, array $values, ?string $keyset, ProductSort $sort, int $limit, int $budget): array
    {
        $column = $sort->byUpdatedAt() ? 'updated_at' : 'name';
        $direction = $sort->isDescending() ? 'DESC' : 'ASC';
        // The index of the sort is read in its order; the page is the first rows of it that have the phrase, so the join
        // that fetches their other columns (and checks the other filters) is lazy and stops with the LIMIT.
        $sql = 'SELECT p.id, p.name, p.tax_class, p.updated_at FROM (SELECT w0.id, w0.name'.($sort->byUpdatedAt() ? ', w0.updated_at' : '').' FROM catalog_product w0'
            .(null === $keyset ? '' : ' WHERE '.$keyset)
            ." ORDER BY w0.{$column} {$direction}, w0.id {$direction} LIMIT {$budget}) w JOIN catalog_product p ON p.id = w.id WHERE w.name ILIKE :pattern"
            .([] === $conditions ? '' : ' AND '.implode(' AND ', $conditions))
            ." ORDER BY w.{$column} {$direction}, w.id {$direction} LIMIT {$limit}";

        return self::products($this->connection->fetchAllAssociative($sql, $values));
    }

    /**
     * @param list<string>         $conditions
     * @param array<string, mixed> $values
     *
     * @return array{list<BackofficeProduct>, bool} the first rows of the candidates and whether they were cut off at the cap
     */
    private function candidates(PhraseRoute $route, string $term, array $conditions, array $values, ?string $keyset, ProductSort $sort, int $limit, int $cap): array
    {
        $column = $sort->byUpdatedAt() ? 'updated_at' : 'name';
        $direction = $sort->isDescending() ? 'DESC' : 'ASC';
        // The candidates are a CTE of their own (MATERIALIZED), so the planner chooses their plan for all of them, the GIN
        // index, and not for the LIMIT that the cap puts on them: the cap only stops reading them, which the CTE does lazily.
        $sql = 'WITH m AS MATERIALIZED (SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE '.$this->candidateConditions($route, $conditions, $keyset).') '
            .'SELECT c.id, c.name, c.tax_class, c.updated_at, count(*) OVER () AS candidates FROM (SELECT * FROM m LIMIT '.($cap + 1).') c'
            ." ORDER BY c.{$column} {$direction}, c.id {$direction} LIMIT {$limit}";
        $rows = $this->bitmapScansOnly(fn (): mixed => $this->connection->fetchAllAssociative($sql, $values + ('' === $route->candidateCondition() ? [] : ['term' => $term])));
        \assert(\is_array($rows));

        /** @var list<array<string, mixed>> $rows */
        $found = [] === $rows ? 0 : $rows[0]['candidates'];
        \assert(\is_int($found));

        return [self::products($rows), $found > $cap];
    }

    /**
     * Runs a read with only bitmap scans allowed, and only for it: in a transaction of its own (a savepoint inside the
     * caller's), rolled back whatever the read does, which takes the settings back (see the class comment).
     *
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function bitmapScansOnly(callable $read): mixed
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(self::BITMAP_SCANS_ONLY);
            $result = $read();
        } catch (\Throwable $failure) {
            try {
                $this->connection->rollBack();
            } catch (\Throwable) {
                // The connection is gone or the transaction already is: the failure of the read is the one to report.
            }

            throw $failure;
        }
        $this->connection->rollBack();

        return $result;
    }

    /**
     * @param list<string> $conditions
     */
    private function candidateConditions(PhraseRoute $route, array $conditions, ?string $keyset): string
    {
        // For the bigrams the exact condition is on an expression the trigram index does not match: the planner would take that
        // index for it (it may look selective by the estimate), but for such a phrase it returns a large part of the table.
        $where = [PhraseRoute::Bigrams === $route ? "(p.name || '') ILIKE :pattern" : 'p.name ILIKE :pattern', ...$conditions];
        if ('' !== $route->candidateCondition()) {
            array_unshift($where, $route->candidateCondition());
        }
        if (null !== $keyset) {
            $where[] = $keyset;
        }

        return implode(' AND ', $where);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<BackofficeProduct>
     */
    private static function products(array $rows): array
    {
        return array_map(static function (array $row): BackofficeProduct {
            \assert(\is_string($row['id']) && \is_string($row['name']) && \is_string($row['tax_class']) && \is_string($row['updated_at']));

            return new BackofficeProduct($row['id'], $row['name'], $row['tax_class'], new \DateTimeImmutable($row['updated_at']));
        }, $rows);
    }
}
