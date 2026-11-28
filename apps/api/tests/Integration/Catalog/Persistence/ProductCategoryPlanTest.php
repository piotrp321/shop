<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalBackofficeProductSource;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Support\MeasuresRowsRead;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The category filter of the product list on 100 000 products with their categories, with the plans the planner chooses on its
 * own statistics (after ANALYZE) for the statements as the source sends them (the test adds no switch of its own; the source's own
 * settings are replayed from the recording). The work of every statement of a page and of a count is measured as the rows it
 * reads from catalog_product, and bounded by the variable of the class of the case: the categories of the subtree times the
 * page, the products that are in the subtree only by an additional category (they have no key of the sort in the table of the
 * memberships, so they are read all), the limit of the count, the hits of a phrase; never the size of the table.
 *
 * The tables are temporary copies of catalog_product, catalog_category and catalog_product_category (their definitions with the
 * indexes) that take the place of the real ones for the connection of the test. A department D has 20 leaves with a sixth of the
 * products as main ones and 11 000 products that are in them only as additional ones (1 000 of them in two leaves); a small
 * category S has the last 15 products, a big category E products 70 001 to 90 000 (late in the order of the names, in both orders).
 */
final class ProductCategoryPlanTest extends KernelTestCase
{
    use MeasuresRowsRead;

    private const int PRODUCTS = 100_000;
    private const int PAGE = 26;
    private const int COUNT_LIMIT = 10_000;
    private const int SLACK = 300;
    /** The products of a leaf of the department (a sixth of the table over 20 leaves), rounded up. */
    private const int LEAF = 1_000;
    /** The department and its 20 leaves. */
    private const int DEPARTMENT_CATEGORIES = 21;
    private const string DEPARTMENT = '00000000-0000-4000-8000-000000000001';
    private const string SMALL = '00000000-0000-4000-8000-000000000002';
    private const string BIG = '00000000-0000-4000-8000-000000000003';

    private Connection $connection;
    private RecordingConnection $recording;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        foreach (['catalog_category', 'catalog_product', 'catalog_product_category'] as $table) {
            $this->connection->executeStatement("CREATE TEMP TABLE {$table} (LIKE public.{$table} INCLUDING ALL)");
        }
        $leaf = static fn (string $i): string => "('00000000-0000-4000-8001-'||lpad(({$i})::text, 12, '0'))::uuid";
        $other = static fn (string $i): string => "('00000000-0000-4000-8002-'||lpad(({$i})::text, 12, '0'))::uuid";
        $this->connection->executeStatement(
            "INSERT INTO catalog_category (id, name, slug, path, parent_id, position, version) VALUES
                ('".self::DEPARTMENT."', 'Dział', 'dzial', '/".self::DEPARTMENT."/', NULL, 0, 1),
                ('".self::SMALL."', 'Mała', 'mala', '/".self::SMALL."/', NULL, 1, 1),
                ('".self::BIG."', 'Duża późna', 'duza', '/".self::BIG."/', NULL, 2, 1)",
        );
        $this->connection->executeStatement(
            "INSERT INTO catalog_category (id, name, slug, path, parent_id, position, version)
             SELECT {$leaf('i')}, 'Liść '||i, 'lisc-'||i, '/".self::DEPARTMENT."/'||{$leaf('i')}||'/', '".self::DEPARTMENT."', i, 1 FROM generate_series(1, 20) i",
        );
        $this->connection->executeStatement(
            "INSERT INTO catalog_category (id, name, slug, path, parent_id, position, version)
             SELECT {$other('j')}, 'Inna '||j, 'inna-'||j, '/'||{$other('j')}||'/', NULL, 100 + j, 1 FROM generate_series(1, 30) j",
        );
        $this->connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, primary_category_id, version, variant_axes, status, updated_at)
             SELECT ('00000000-0000-4000-8003-'||lpad(n::text, 12, '0'))::uuid, 'produkt-'||n,
                    'Produkt '||lpad(n::text, 6, '0')||' '||CASE WHEN n > 50000 AND n % 80 = 7 THEN 'snowboardowe' ELSE 'zwykły' END,
                    '', 'standard',
                    CASE WHEN n % 25 = 12 THEN '[]'::jsonb ELSE '[{\"key\": \"originals/a.jpg\", \"alt\": \"a\"}]'::jsonb END,
                    CASE WHEN n > 99985 THEN '".self::SMALL."'::uuid
                         WHEN n BETWEEN 70001 AND 90000 THEN '".self::BIG."'::uuid
                         WHEN n % 6 = 0 THEN {$leaf('(n / 6) % 20 + 1')}
                         ELSE {$other('n % 30 + 1')} END,
                    1, '[]'::jsonb, CASE WHEN n % 25 = 0 THEN 'draft' ELSE 'published' END,
                    timestamptz '2026-01-01 00:00:00+00' + n * interval '1 minute'
             FROM generate_series(1, :products) AS n",
            ['products' => self::PRODUCTS],
        );
        // Additional members of the department: every 10th product (not of the department by its main category), 1 000 of them in two leaves.
        $this->connection->executeStatement(
            "INSERT INTO catalog_product_category (product_id, category_id)
             SELECT ('00000000-0000-4000-8003-'||lpad(n::text, 12, '0'))::uuid, {$leaf('(n / 10) % 20 + 1')} FROM generate_series(3, :products, 10) n",
            ['products' => self::PRODUCTS],
        );
        $this->connection->executeStatement(
            "INSERT INTO catalog_product_category (product_id, category_id)
             SELECT ('00000000-0000-4000-8003-'||lpad(n::text, 12, '0'))::uuid, {$leaf('(n / 10 + 7) % 20 + 1')} FROM generate_series(3, :products, 100) n",
            ['products' => self::PRODUCTS],
        );
        foreach (['catalog_category', 'catalog_product', 'catalog_product_category'] as $table) {
            $this->connection->executeStatement("ANALYZE {$table}");
        }
        $this->recording = RecordingConnection::like($this->connection);
    }

    public function testTheIndexesOfTheCategoryExist(): void
    {
        $expected = [
            'catalog_product_category_name_id_idx' => '(primary_category_id, name, id)',
            'catalog_product_category_updated_at_id_idx' => '(primary_category_id, updated_at, id)',
            'catalog_product_category_draft_name_id_idx' => "(primary_category_id, name, id) WHERE ((status)::text = 'draft'::text)",
            'catalog_product_category_draft_updated_at_id_idx' => "(primary_category_id, updated_at, id) WHERE ((status)::text = 'draft'::text)",
            'catalog_product_category_without_images_name_id_idx' => "(primary_category_id, name, id) WHERE (images = '[]'::jsonb)",
            'catalog_product_category_without_images_updated_at_id_idx' => "(primary_category_id, updated_at, id) WHERE (images = '[]'::jsonb)",
        ];
        foreach ($expected as $name => $end) {
            $definition = $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'catalog_product' AND indexname = ?", [$name]);
            self::assertIsString($definition, $name);
            self::assertStringEndsWith($end, $definition, $name);
        }
    }

    /**
     * @return iterable<string, array{string, int, ?ProductStatus, bool, bool}> the category, its categories, the status, without images, whether the count is checked by the rows
     */
    public static function categories(): iterable
    {
        yield 'a small category at the end of the order' => [self::SMALL, 1, null, false, true];
        yield 'a big leaf at the end of the order' => [self::BIG, 1, null, false, true];
        yield 'the department' => [self::DEPARTMENT, self::DEPARTMENT_CATEGORIES, null, false, true];
        yield 'the department, the published ones' => [self::DEPARTMENT, self::DEPARTMENT_CATEGORIES, ProductStatus::Published, false, true];
        yield 'the department, the drafts' => [self::DEPARTMENT, self::DEPARTMENT_CATEGORIES, ProductStatus::Draft, false, true];
        // The count of the products without images reads them through their partial index of the category by an index only scan, which the
        // planner prefers to the partial index by id only for a table that has been vacuumed (the table of the test has not, and cannot be in
        // its transaction); the plan on a vacuumed copy of `medium` is in the report of PERF-7-3.
        yield 'the department, without images' => [self::DEPARTMENT, self::DEPARTMENT_CATEGORIES, null, true, false];
    }

    #[DataProvider('categories')]
    public function testThePagesOfACategoryReadAsMuchAsItsClassAllowsAndNotTheTable(string $category, int $categories, ?ProductStatus $status, bool $withoutImages, bool $countChecked): void
    {
        $source = new DbalBackofficeProductSource($this->recording, new BoundedCounter($this->recording));
        $path = CategoryPath::fromString('/'.$category.'/');
        // A branch per category of the subtree (a page and one more row each). The products that are in the subtree only by an additional
        // category are a set of their own (the CTE `additional`), linear in the memberships of the subtree (or in the table when the
        // planner hash joins it): the cost of K1 that the specification accepts, measured on `medium` in the report, not bounded here.
        $bound = $categories * (self::PAGE + 1) + self::SLACK;

        foreach ([ProductSort::Name, ProductSort::NameDescending, ProductSort::UpdatedAtDescending, ProductSort::UpdatedAtAscending] as $sort) {
            $this->recording->forget();
            $first = $source->page(null, $path, $status, null, $sort, self::PAGE, $withoutImages);
            $read = $this->rowsRead('additional');
            self::assertNotSame([], $first, $sort->value);
            self::assertLessThanOrEqual($bound, $read, \sprintf("first page by %s read %d rows of %d (class bound %d)\n%s", $sort->value, $read, self::PRODUCTS, $bound, $this->plans()));

            $this->recording->forget();
            $source->page(null, $path, $status, ProductCursor::after($first[\count($first) - 1], $sort), $sort, self::PAGE, $withoutImages);
            $read = $this->rowsRead('additional');
            self::assertLessThanOrEqual($bound, $read, \sprintf("second page by %s read %d rows of %d (class bound %d)\n%s", $sort->value, $read, self::PRODUCTS, $bound, $this->plans()));
        }
    }

    #[DataProvider('categories')]
    public function testTheCountOfACategoryReadsAsMuchAsItsClassAllowsAndNotTheTable(string $category, int $categories, ?ProductStatus $status, bool $withoutImages, bool $checked): void
    {
        if (!$checked) {
            self::markTestSkipped('The plan of this count depends on a vacuumed table, see the data provider.');
        }
        $source = new DbalBackofficeProductSource($this->recording, new BoundedCounter($this->recording));

        $this->recording->forget();
        $count = $source->count(null, CategoryPath::fromString('/'.$category.'/'), $status, $withoutImages);

        self::assertGreaterThan(0, $count->value);
        $read = $this->rowsRead('additional');
        // The count stops at the limit: a branch reads at most the limit (and the planner reads a whole category when it has fewer).
        // A branch that is ordered (the status, without images) is read whole, so the count may go over the limit by one leaf of the department.
        $bound = min($count->value, self::COUNT_LIMIT + 1) + $categories + self::LEAF + self::SLACK;
        self::assertLessThanOrEqual($bound, $read, \sprintf("count read %d rows of %d (class bound %d)\n%s", $read, self::PRODUCTS, $bound, $this->plans()));
    }

    /**
     * A department is a part of the table that is dense in the order of the sorts (the products of its categories, a sixth by the main one and
     * a part by the additional ones), so its page is found in the first rows of the global index of the sort: the walk of the budget, with the
     * membership of the subtree checked, fills it, and the products that are in the subtree only by additional categories are not read at all
     * (they cost the memberships of the subtree, or the table). The count of such a department reaches the limit in its branches.
     */
    public function testThePagesOfADenseCategoryReadTheWalkOfTheBudgetNotTheAdditionalMembers(): void
    {
        $budget = 5_000;
        $source = new DbalBackofficeProductSource($this->recording, new BoundedCounter($this->recording), phraseBudget: $budget);
        $path = CategoryPath::fromString('/'.self::DEPARTMENT.'/');

        foreach ([[null, false], [ProductStatus::Published, false], [ProductStatus::Draft, false], [null, true]] as [$status, $withoutImages]) {
            foreach ([ProductSort::Name, ProductSort::NameDescending, ProductSort::UpdatedAtDescending, ProductSort::UpdatedAtAscending] as $sort) {
                $this->recording->forget();
                $first = $source->page(null, $path, $status, null, $sort, self::PAGE, $withoutImages);
                $read = $this->rowsRead();
                self::assertCount(self::PAGE, $first);
                self::assertLessThanOrEqual($budget + self::SLACK, $read, \sprintf("first page by %s (%s) read %d rows of %d (budget %d)\n%s", $sort->value, $status?->value ?? 'any', $read, self::PRODUCTS, $budget, $this->plans()));

                $this->recording->forget();
                $source->page(null, $path, $status, ProductCursor::after($first[self::PAGE - 1], $sort), $sort, self::PAGE, $withoutImages);
                $read = $this->rowsRead();
                self::assertLessThanOrEqual($budget + self::SLACK, $read, \sprintf("second page by %s (%s) read %d rows of %d (budget %d)\n%s", $sort->value, $status?->value ?? 'any', $read, self::PRODUCTS, $budget, $this->plans()));
            }
        }
    }

    /**
     * R2d: a phrase of a middle frequency (625 hits in the catalog, none in the department) with the department: the candidates of the
     * phrase are checked for the department, and what they cost is their number (the budget of the walk of the first rows, then the hits),
     * not the department (17 000 products by the main category and 11 000 by the additional ones) nor the table.
     */
    public function testAPhraseOfAMiddleFrequencyInTheDepartmentReadsItsHitsAndNotTheDepartment(): void
    {
        $budget = 2_000;
        $hits = 625;
        $source = new DbalBackofficeProductSource($this->recording, new BoundedCounter($this->recording), phraseBudget: $budget);
        $path = CategoryPath::fromString('/'.self::DEPARTMENT.'/');

        foreach ([ProductSort::Name, ProductSort::UpdatedAtDescending] as $sort) {
            $this->recording->forget();
            $source->page('snowboardowe', $path, null, null, $sort, self::PAGE);
            $read = $this->rowsRead();
            $bound = $budget + 3 * $hits + self::SLACK;
            self::assertLessThanOrEqual($bound, $read, \sprintf("page by %s read %d rows of %d (class bound %d)\n%s", $sort->value, $read, self::PRODUCTS, $bound, $this->plans()));
        }
    }
}
