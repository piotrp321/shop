<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Application\Query\ProductSort;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalBackofficeProductSource;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The phrase of the product list on 100 000 products, with the plans the planner chooses on its own statistics (after
 * ANALYZE), not steered by switches. The work of every statement of a page and of a count is measured as the rows it
 * reads from the table (rows returned by a scan node plus the rows it removed by the filter and the recheck, times
 * the loops), and bounded by the variable of the class of the phrase: the budget of the walk of the index of the sort,
 * the number of hits of the candidates, never the size of the table.
 *
 * The products are in a temporary table that takes the place of catalog_product for the connection of the test (a copy
 * of its definition with its indexes), so the table of the test database is not filled with rows that the rollback
 * leaves behind as garbage. The names are `Produkt <six digits> <tail>`, so the order of the names is the order of n and
 * the tail decides which phrase a product has.
 */
final class ProductPhrasePlanTest extends KernelTestCase
{
    private const int PRODUCTS = 100_000;
    private const int PAGE = 26;
    private const int COUNT_LIMIT = 10_000;
    /** The budget of the first walk of the source when it is not given. */
    private const int DEFAULT_BUDGET = 20_000;
    /** What a statement may read beyond its class: the lookups of the rows of the page and the planner's rounding. */
    private const int SLACK = 300;

    private Connection $connection;
    private RecordingConnection $recording;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('CREATE TEMP TABLE catalog_product (LIKE public.catalog_product INCLUDING ALL)');
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, primary_category_id, version, variant_axes, status, updated_at)
                SELECT gen_random_uuid(), 'produkt-'||n,
                       'Produkt '||lpad(n::text, 6, '0')||' '||CASE
                           WHEN n BETWEEN 99001 AND 99300 THEN 'termosx stalowy'
                           WHEN n % 2500 = 7 THEN 'zestaw qx'
                           WHEN n % 2500 = 11 THEN 'zestaw #!#'
                           WHEN n % 2500 = 13 THEN 'zestaw a.b'
                           WHEN n % 2500 = 17 THEN 'zestaw ab-cd'
                           WHEN n % 1000 = 3 THEN 'ŁÓDŹ kajak'
                           WHEN n % 5 = 0 THEN 'gęsty wzór'
                           WHEN n > 60000 AND n % 2 = 1 THEN 'korelowany wzór'
                           ELSE 'zwykły' END,
                       '', 'standard', '[{"key": "originals/a.jpg", "alt": "a"}]'::jsonb, gen_random_uuid(), 1, '[]'::jsonb, 'published',
                       timestamptz '2026-01-01 00:00:00+00' + n * interval '1 minute'
                FROM generate_series(1, :products) AS n
                SQL,
            ['products' => self::PRODUCTS],
        );
        $this->connection->executeStatement('ANALYZE catalog_product');
        $this->recording = RecordingConnection::like($this->connection);
    }

    /**
     * @return iterable<string, array{string, int, string}> the term, the number of its hits and what bounds it
     */
    public static function phrases(): iterable
    {
        yield 'dense phrase: the walk fills the page' => ['gęsty', 20_000, 'budget'];
        yield 'phrase clustered at the end of the alphabet' => ['termosx', 300, 'hits'];
        yield 'polish capitals found by a lower case phrase' => ['łódź', 100, 'hits'];
        yield '2 characters, rare' => ['qx', 40, 'hits'];
        yield 'punctuation only, rare' => ['#!#', 40, 'hits'];
        yield 'a.b: only a non-selective trigram' => ['a.b', 40, 'hits'];
        yield 'ab-cd' => ['ab-cd', 40, 'hits'];
    }

    #[DataProvider('phrases')]
    public function testThePageOfAPhraseReadsAsMuchAsItsClassAllowsAndNotTheTable(string $term, int $hits, string $variable): void
    {
        $source = $this->source(self::DEFAULT_BUDGET);

        foreach ([ProductSort::Name, ProductSort::UpdatedAtDescending] as $sort) {
            $this->recording->queries = [];
            $page = $source->page($term, null, null, null, $sort, self::PAGE);

            self::assertNotSame([], $page, $term);
            $read = $this->rowsRead();
            $bound = 'budget' === $variable ? self::DEFAULT_BUDGET + self::SLACK : self::DEFAULT_BUDGET + 3 * $hits + self::SLACK;
            self::assertLessThanOrEqual($bound, $read, \sprintf("page of '%s' by %s read %d rows of %d (class bound %d)\n%s", $term, $sort->value, $read, self::PRODUCTS, $bound, $this->plans()));
        }
    }

    #[DataProvider('phrases')]
    public function testTheCountOfAPhraseReadsAsMuchAsItsClassAllows(string $term, int $hits, string $variable): void
    {
        $source = $this->source(self::DEFAULT_BUDGET);

        $this->recording->queries = [];
        $count = $source->count($term, null, null);

        self::assertGreaterThan(0, $count->value, $term);
        $read = $this->rowsRead();
        $bound = self::DEFAULT_BUDGET + 3 * min($hits, self::COUNT_LIMIT + 1) + self::SLACK;
        self::assertLessThanOrEqual($bound, $read, \sprintf("count of '%s' read %d rows of %d (class bound %d)\n%s", $term, $read, self::PRODUCTS, $bound, $this->plans()));
    }

    #[DataProvider('bigramPhrases')]
    public function testAPhraseWithoutASelectiveTrigramIsServedByTheBigramIndex(string $term): void
    {
        $index = $this->indexWith('catalog_name_bigrams');
        $source = $this->source(self::DEFAULT_BUDGET);

        $this->recording->queries = [];
        $source->page($term, null, null, null, ProductSort::Name, self::PAGE);
        $page = $this->plans();
        $this->recording->queries = [];
        $source->count($term, null, null);
        $count = $this->plans();

        self::assertStringContainsString($index, $page, "page of '".$term."'");
        self::assertStringContainsString($index, $count, "count of '".$term."'");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bigramPhrases(): iterable
    {
        foreach (['qx', '#!#', 'a.b'] as $term) {
            yield $term => [$term];
        }
    }

    public function testAPhraseWithAWordOfThreeCharactersStaysOnTheTrigramIndex(): void
    {
        $index = $this->indexWith('gin_trgm_ops');
        $bigrams = $this->indexWith('catalog_name_bigrams');
        $source = $this->source(self::DEFAULT_BUDGET);

        $this->recording->queries = [];
        $source->page('termosx', null, null, null, ProductSort::Name, self::PAGE);
        $plans = $this->plans();

        self::assertStringContainsString($index, $plans);
        self::assertStringNotContainsString($bigrams, $plans);
    }

    /**
     * A phrase that is frequent and starts in the order of the sort after the first budget: the budget grows by steps
     * and the page is found by a walk that reads up to the position of its last row, not the table.
     */
    public function testAFrequentPhraseCorrelatedWithTheSortGrowsTheBudgetInsteadOfReadingItsCandidates(): void
    {
        $budget = 2_000;
        $source = $this->source($budget);

        foreach ([ProductSort::Name, ProductSort::UpdatedAtDescending] as $sort) {
            $this->recording->queries = [];
            $page = $source->page('korelowany', null, null, null, $sort, self::PAGE);

            self::assertCount(self::PAGE, $page);
            // Hits are odd n above 60 000: by name the 26th one is at n of about 60 050, by the last change (descending) at the end.
            $position = ProductSort::Name === $sort ? 60_100 : 100;
            $read = $this->rowsRead();
            // The walks of the steps before the one that fills the page (budgets 2 000 and 20 000 and 200 000 are capped by the
            // position), the candidates cut off at their cap of each step, and the last walk up to the position.
            $bound = $budget + 3 * (intdiv($budget, 2) + 1) + 20_000 + 3 * (10_000 + 1) + $position + self::SLACK;
            self::assertLessThanOrEqual($bound, $read, \sprintf("page by %s read %d rows (class bound %d)\n%s", $sort->value, $read, $bound, $this->plans()));
            if (ProductSort::UpdatedAtDescending === $sort) {
                self::assertLessThanOrEqual($budget + self::SLACK, $read, 'the first walk fills the page');
            }
        }
    }

    private function source(int $budget): DbalBackofficeProductSource
    {
        if (self::DEFAULT_BUDGET === $budget) {
            return new DbalBackofficeProductSource($this->recording, new BoundedCounter($this->recording));
        }

        return new DbalBackofficeProductSource($this->recording, new BoundedCounter($this->recording), phraseBudget: $budget);
    }

    private function indexWith(string $needle): string
    {
        $name = $this->connection->fetchOne(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'catalog_product' AND schemaname LIKE 'pg_temp%' AND indexdef LIKE ?",
            ['%'.$needle.'%'],
        );
        self::assertIsString($name, 'no index of the temporary copy contains '.$needle);

        return $name;
    }

    /**
     * The rows read from catalog_product by all the statements recorded since the last reset.
     */
    private function rowsRead(): int
    {
        $total = 0.0;
        foreach ($this->analyzedPlans() as $plan) {
            $total += self::rowsReadBy($plan);
        }

        return (int) ceil($total);
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function rowsReadBy(array $node): float
    {
        $read = 0.0;
        $relation = $node['Relation Name'] ?? null;
        $type = \is_string($node['Node Type'] ?? null) ? $node['Node Type'] : '';
        if ('catalog_product' === $relation && str_contains($type, 'Scan') && !str_contains($type, 'Bitmap Index')) {
            $perLoop = (float) ($node['Actual Rows'] ?? 0) + (float) ($node['Rows Removed by Filter'] ?? 0) + (float) ($node['Rows Removed by Index Recheck'] ?? 0);
            $read += $perLoop * (float) ($node['Actual Loops'] ?? 1);
        }
        foreach ($node['Plans'] ?? [] as $child) {
            \assert(\is_array($child));
            $read += self::rowsReadBy($child);
        }

        return $read;
    }

    /**
     * @return list<array<string, mixed>> the plan trees (EXPLAIN ANALYZE) of the recorded statements
     */
    private function analyzedPlans(): array
    {
        $plans = [];
        foreach ($this->recording->queries as [$sql, $params, $types]) {
            $json = $this->connection->executeQuery('EXPLAIN (ANALYZE, FORMAT JSON) '.$sql, $params, $types)->fetchOne();
            \assert(\is_string($json));
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded) && isset($decoded[0]['Plan']) && \is_array($decoded[0]['Plan']));
            $plans[] = $decoded[0]['Plan'];
        }

        return $plans;
    }

    /**
     * The plans of the recorded statements as text, for the message of a failure and for the checks of the indexes.
     */
    private function plans(): string
    {
        $text = [];
        foreach ($this->recording->queries as [$sql, $params, $types]) {
            $lines = $this->connection->executeQuery('EXPLAIN (ANALYZE) '.$sql, $params, $types)->fetchFirstColumn();
            $text[] = $sql."\n".implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $lines));
        }

        return implode("\n----\n", $text);
    }
}
