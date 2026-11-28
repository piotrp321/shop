<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalBackofficeProductSource;
use App\Shared\Domain\BoundedCount;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P7 for the phrase of the product list: the pages (rows, order, cursors) and the counts of DbalBackofficeProductSource
 * are the same as the ones of the statement it had before the phrase got its own way of choosing the plan (the reference
 * below is that statement, unchanged), for every sort, with and without the other filters, walking the pages by the
 * cursor to the end. The source runs with its default budget and with budgets of 1 to 100 rows, so that every way the phrase can
 * take (the walk, the candidates of the index, the growing of the budget, the end of the order reached by the walk) is taken on
 * this small catalog, and the result does not depend on the budget.
 *
 * The catalog has names with Polish characters and capitals, the characters of the pattern (`%`, `_`, `\`), phrases of
 * two characters and of punctuation only, equal names and equal moments (so the id decides), products of the main and
 * of additional categories (also two in the same subtree), drafts and products without images.
 */
final class ProductPhraseEquivalenceTest extends KernelTestCase
{
    private const string ROOT = '00000000-0000-4000-8000-0000000000a1';
    private const string CHILD = '00000000-0000-4000-8000-0000000000a2';
    private const string OTHER = '00000000-0000-4000-8000-0000000000b1';
    private const int PAGE = 4;

    private Connection $connection;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->seed();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function phrases(): iterable
    {
        foreach (['termos', 'TeRmOs', 'łódź', 'ŁÓDŹ', 'ód', 'żółć', 'qx', 'QX', '#!#', 'a.b', 'ab-cd', '%', '_', '\\', '0% b', 'a_', 'er', 'zestaw', 'zzz nie ma'] as $term) {
            yield $term => [$term];
        }
    }

    #[DataProvider('phrases')]
    public function testPagesAndCountsAreTheSameAsTheReferenceStatementWithTheDefaultBudget(string $term): void
    {
        $this->assertEquivalent($term, new DbalBackofficeProductSource($this->connection, new BoundedCounter($this->connection)));
    }

    #[DataProvider('phrases')]
    public function testPagesAndCountsAreTheSameWhenEveryWayOfThePhraseIsTaken(string $term): void
    {
        // 1 row to 13 rows: every step of the budget is a short one on about 70 products; 100 rows: the walk reaches the end of the order
        // (also on the pages after a cursor, which return fewer rows than the budget).
        foreach ([1, 2, 3, 5, 7, 13, 100] as $budget) {
            $source = new DbalBackofficeProductSource($this->connection, new BoundedCounter($this->connection), phraseBudget: $budget);

            $this->assertEquivalent($term, $source, 'budget '.$budget);
        }
    }

    private function assertEquivalent(string $term, DbalBackofficeProductSource $source, string $label = 'default'): void
    {
        $filters = [
            'no filter' => [null, null, false],
            'category root' => [CategoryPath::fromString('/'.self::ROOT.'/'), null, false],
            'category child' => [CategoryPath::fromString('/'.self::ROOT.'/'.self::CHILD.'/'), null, false],
            'published' => [null, ProductStatus::Published, false],
            'draft' => [null, ProductStatus::Draft, false],
            'without images' => [null, null, true],
            'root, published, without images' => [CategoryPath::fromString('/'.self::ROOT.'/'), ProductStatus::Published, true],
        ];
        foreach ($filters as $filterLabel => [$category, $status, $withoutImages]) {
            $where = \sprintf("'%s', %s, %s", $term, $filterLabel, $label);
            foreach ([10_000, 3] as $limit) {
                self::assertEquals(
                    $this->referenceCount($term, $category, $status, $withoutImages, $limit),
                    $source->count($term, $category, $status, $withoutImages, $limit),
                    'count to '.$limit.' of '.$where,
                );
            }
            foreach (ProductSort::cases() as $sort) {
                $after = null;
                $pages = 0;
                do {
                    $expected = $this->referencePage($term, $category, $status, $after, $sort, self::PAGE + 1, $withoutImages);
                    $actual = $source->page($term, $category, $status, $after, $sort, self::PAGE + 1, $withoutImages);

                    self::assertSame(self::describe($expected), self::describe($actual), \sprintf('page %d by %s of %s', $pages, $sort->value, $where));
                    $after = \count($expected) > self::PAGE ? ProductCursor::after($expected[self::PAGE - 1], $sort) : null;
                    ++$pages;
                } while (null !== $after && $pages < 60);
            }
        }
    }

    public function testTheCatalogOfTheTestHasWhatTheEquivalenceIsAbout(): void
    {
        self::assertGreaterThan(3, \count($this->referencePage('termos', null, null, null, ProductSort::Name, 100, false)));
        self::assertNotSame([], $this->referencePage('łódź', null, null, null, ProductSort::Name, 100, false));
        self::assertNotSame([], $this->referencePage('qx', null, null, null, ProductSort::Name, 100, false));
        self::assertNotSame([], $this->referencePage('#!#', null, ProductStatus::Draft, null, ProductSort::Name, 100, false));
        self::assertNotSame([], $this->referencePage('zestaw', null, null, null, ProductSort::Name, 100, true));
        self::assertNotSame([], $this->referencePage('%', null, null, null, ProductSort::Name, 100, false));
    }

    /**
     * @param list<BackofficeProduct> $products
     *
     * @return list<string>
     */
    private static function describe(array $products): array
    {
        return array_map(static fn (BackofficeProduct $p): string => $p->id.'|'.$p->name.'|'.$p->taxClass.'|'.$p->updatedAt->format('Y-m-d H:i:s.uP'), $products);
    }

    private function seed(): void
    {
        $this->connection->executeStatement("INSERT INTO catalog_category (id, name, slug, path, parent_id, position, version) VALUES
            ('".self::ROOT."', 'Root', 'root', '/".self::ROOT."/', NULL, 0, 1),
            ('".self::CHILD."', 'Child', 'child', '/".self::ROOT.'/'.self::CHILD."/', '".self::ROOT."', 0, 1),
            ('".self::OTHER."', 'Other', 'other', '/".self::OTHER."/', NULL, 1, 1)");
        $names = [
            'Termos stalowy', 'TERMOS stalowy', 'termos', 'Termos turystyczny 1 l', 'Łódź kajakowa', 'ŁÓDŹ', 'łóDŹ turystyczna', 'Żółć i źdźbło', 'ŻÓŁW',
            'Zażółć gęślą jaźń', 'Kijki a.b', 'Bidon ab-cd', 'Zestaw #!# 1', 'Zestaw #!# 2', 'Zestaw a_b', 'Zestaw axb', '100% bawełna', 'Plecak \\kółko', 'Plecak zwykły',
            'Namiot QX', 'Namiot qx', 'Bluza er', 'Zestaw 3 w 1', 'Zestaw 3 w 1',
        ];
        $moments = ['2026-11-01 10:00:00', '2026-11-02 10:00:00', '2026-11-03 10:00:00'];
        $number = 0;
        foreach ([1, 2, 3] as $_round) {
            foreach ($names as $name) {
                ++$number;
                $id = \sprintf('00000000-0000-4000-8000-%012d', $number);
                $main = [self::ROOT, self::CHILD, self::OTHER][$number % 3];
                $this->connection->executeStatement(
                    "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, primary_category_id, version, variant_axes, status, updated_at)
                     VALUES (?, ?, ?, '', 'standard', ?::jsonb, ?, 1, '[]'::jsonb, ?, ?)",
                    [$id, 'p-'.$number, $name, 0 === $number % 5 ? '[]' : '[{"key": "originals/a.jpg", "alt": "a"}]', $main, 0 === $number % 7 ? 'draft' : 'published', $moments[$number % 3].'+00'],
                    [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
                );
                // Every fourth product is also in the child (and so in the root's subtree twice when it is of the root), every sixth in the other category.
                foreach (array_unique(array_filter([0 === $number % 4 ? self::CHILD : null, 0 === $number % 6 ? self::ROOT : null, 0 === $number % 9 ? self::OTHER : null])) as $additional) {
                    if ($additional !== $main) {
                        $this->connection->executeStatement('INSERT INTO catalog_product_category (product_id, category_id) VALUES (?, ?)', [$id, $additional]);
                    }
                }
            }
        }
        $this->connection->executeStatement('ANALYZE catalog_product');
    }

    // The statement of DbalBackofficeProductSource as it was before the phrase: the reference of the equivalence.

    private const string CATEGORY = <<<'SQL'
        (
            p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
            OR EXISTS (
                SELECT 1 FROM catalog_product_category pc
                WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
            )
        )
        SQL;

    /**
     * @return array{list<string>, array<string, mixed>}
     */
    private static function referenceFilter(string $term, ?CategoryPath $category, ?ProductStatus $status, bool $withoutImages): array
    {
        $conditions = ['p.name ILIKE :pattern'];
        $parameters = ['pattern' => '%'.strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%'];
        if (null !== $category) {
            $conditions[] = self::CATEGORY;
            $parameters['prefix'] = strtr($category->toString(), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%';
        }
        if (null !== $status) {
            $conditions[] = "p.status = '".$status->value."'";
        }
        if ($withoutImages) {
            $conditions[] = "p.images = '[]'::jsonb";
        }

        return [$conditions, $parameters];
    }

    /**
     * @return list<BackofficeProduct>
     */
    private function referencePage(string $term, ?CategoryPath $category, ?ProductStatus $status, ?ProductCursor $after, ProductSort $sort, int $limit, bool $withoutImages): array
    {
        [$conditions, $parameters] = self::referenceFilter($term, $category, $status, $withoutImages);
        $parameters['limit'] = $limit;
        $direction = $sort->isDescending() ? 'DESC' : 'ASC';
        $column = $sort->byUpdatedAt() ? 'p.updated_at' : 'p.name';
        if (null !== $after) {
            $conditions[] = \sprintf(
                $sort->byUpdatedAt() ? '(p.updated_at, p.id) %s (CAST(:after_value AS timestamptz), CAST(:after_id AS uuid))' : '(p.name, p.id) %s (:after_value, CAST(:after_id AS uuid))',
                $sort->isDescending() ? '<' : '>',
            );
            $parameters['after_value'] = $after->value instanceof \DateTimeImmutable ? $after->value->format('Y-m-d H:i:s.uP') : $after->value;
            $parameters['after_id'] = $after->id;
        }
        $sql = 'SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE '.implode(' AND ', $conditions)." ORDER BY {$column} {$direction}, p.id {$direction} LIMIT :limit";

        return array_map(static function (array $row): BackofficeProduct {
            \assert(\is_string($row['id']) && \is_string($row['name']) && \is_string($row['tax_class']) && \is_string($row['updated_at']));

            return new BackofficeProduct($row['id'], $row['name'], $row['tax_class'], new \DateTimeImmutable($row['updated_at']));
        }, $this->connection->executeQuery($sql, $parameters, ['limit' => ParameterType::INTEGER])->fetchAllAssociative());
    }

    private function referenceCount(string $term, ?CategoryPath $category, ?ProductStatus $status, bool $withoutImages, int $limit): BoundedCount
    {
        [$conditions, $parameters] = self::referenceFilter($term, $category, $status, $withoutImages);

        return new BoundedCounter($this->connection)->count('catalog_product p', $conditions, $parameters, limit: $limit);
    }
}
