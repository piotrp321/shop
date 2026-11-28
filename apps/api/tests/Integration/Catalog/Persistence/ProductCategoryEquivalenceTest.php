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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P7 for the category filter of the product list: the pages (rows, order, cursors) and the counts of DbalBackofficeProductSource
 * are the same as the ones of the statement it had before the category had its own way (the reference below is that
 * statement, unchanged), for every sort, with the status and the products without images, walking the pages by the cursor to
 * the end; the category tree, the products of the main and of the additional categories (a product with its main and an additional
 * category in the same subtree, a product with three additional categories in it), an empty category, equal names and equal
 * moments (the id decides). After the category was renamed (the path keeps the identifiers, so nothing changes) and moved the
 * list is that of the new subtree.
 */
final class ProductCategoryEquivalenceTest extends KernelTestCase
{
    private const string ROOT = '00000000-0000-4000-8000-0000000000a1';
    private const string LEFT = '00000000-0000-4000-8000-0000000000a2';
    private const string LEAF = '00000000-0000-4000-8000-0000000000a3';
    private const string RIGHT = '00000000-0000-4000-8000-0000000000a4';
    private const string EMPTY = '00000000-0000-4000-8000-0000000000a5';
    private const string OTHER = '00000000-0000-4000-8000-0000000000b1';
    private const string SIBLING = '00000000-0000-4000-8000-000000000a10';
    private const int PAGE = 4;

    private Connection $connection;
    private ?int $budget = null;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->seed();
    }

    public function testPagesAndCountsAreTheSameAsTheReferenceStatement(): void
    {
        $this->assertEveryListEquivalent();
    }

    /**
     * The walk of the global index that comes before the branches of the category finds the whole page, a part of it or nothing,
     * and ends at the end of the order or before it, whatever the budget is: the lists are the same.
     */
    public function testPagesAndCountsAreTheSameWhateverTheBudgetOfTheWalkIs(): void
    {
        // The walk of a category is a tenth of the budget: walks of 1 to 13 rows, and of 100 rows (the end of the order).
        foreach ([10, 20, 30, 50, 70, 130, 1000] as $budget) {
            $this->budget = $budget;
            $this->assertEveryListEquivalent();
        }
    }

    public function testAfterTheCategoryWasMovedTheListIsTheOneOfTheNewSubtree(): void
    {
        $this->assertEveryListEquivalent();
        $before = $this->names($this->source()->page(null, self::path(self::RIGHT), null, null, ProductSort::Name, 100));

        // LEFT (with LEAF) moves under RIGHT; the name of the category is not part of the path, so a rename changes nothing.
        $this->connection->executeStatement('UPDATE catalog_category SET name = ? WHERE id = ?', ['Renamed', self::LEFT]);
        $this->connection->executeStatement("UPDATE catalog_category SET path = replace(path, '/".self::ROOT.'/'.self::LEFT."/', '/".self::RIGHT.'/'.self::LEFT."/') WHERE path LIKE '/".self::ROOT.'/'.self::LEFT."/%'");
        $this->connection->executeStatement('UPDATE catalog_category SET parent_id = ? WHERE id = ?', [self::RIGHT, self::LEFT]);

        $this->assertEveryListEquivalent();
        $after = $this->names($this->source()->page(null, self::path(self::RIGHT, self::LEFT), null, null, ProductSort::Name, 100));
        $moved = $this->names($this->source()->page(null, self::path(self::RIGHT), null, null, ProductSort::Name, 100));
        self::assertNotSame([], $after);
        foreach ($after as $name) {
            self::assertContains($name, $moved, 'the products of the moved category are in the list of the new parent');
        }
        self::assertGreaterThan(\count($before), \count($moved));
        self::assertSame([], $this->names($this->source()->page(null, CategoryPath::fromString('/'.self::ROOT.'/'.self::LEFT.'/'), null, null, ProductSort::Name, 100)), 'the old path of the moved category has no products');
    }

    public function testTheCatalogOfTheTestHasWhatTheEquivalenceIsAbout(): void
    {
        // A product of the root's subtree with its main and an additional category in it, once; a product with three additional ones in it, once.
        $names = $this->names($this->source()->page(null, self::path(self::ROOT), null, null, ProductSort::Name, 500));
        self::assertSame(1, array_count_values($names)['Podwójny']);
        self::assertSame(1, array_count_values($names)['Potrójny']);
        self::assertSame([], $this->source()->page(null, self::path(self::EMPTY), null, null, ProductSort::Name, 10));
        self::assertNotSame([], $this->referencePage(self::path(self::ROOT), ProductStatus::Draft, null, ProductSort::Name, 100, false));
    }

    private function assertEveryListEquivalent(): void
    {
        $source = $this->source();
        $filters = [
            'no filter' => [null, false],
            'published' => [ProductStatus::Published, false],
            'draft' => [ProductStatus::Draft, false],
            'without images' => [null, true],
            'published without images' => [ProductStatus::Published, true],
        ];
        $categories = ['root' => self::path(self::ROOT), 'left' => self::path(self::ROOT, self::LEFT), 'leaf' => self::path(self::ROOT, self::LEFT, self::LEAF), 'right' => self::path(self::RIGHT), 'empty' => self::path(self::EMPTY), 'other' => self::path(self::OTHER), 'sibling' => self::path(self::SIBLING)];
        $categories['moved left'] = self::path(self::RIGHT, self::LEFT);
        foreach ($categories as $categoryLabel => $category) {
            foreach ($filters as $filterLabel => [$status, $withoutImages]) {
                $where = $categoryLabel.', '.$filterLabel;
                foreach ([10_000, 3] as $limit) {
                    self::assertEquals(
                        $this->referenceCount($category, $status, $withoutImages, $limit),
                        $source->count(null, $category, $status, $withoutImages, $limit),
                        'count to '.$limit.' of '.$where,
                    );
                }
                foreach (ProductSort::cases() as $sort) {
                    $after = null;
                    $pages = 0;
                    do {
                        $expected = $this->referencePage($category, $status, $after, $sort, self::PAGE + 1, $withoutImages);
                        $actual = $source->page(null, $category, $status, $after, $sort, self::PAGE + 1, $withoutImages);

                        self::assertSame(self::describe($expected), self::describe($actual), \sprintf('page %d by %s of %s', $pages, $sort->value, $where));
                        $after = \count($expected) > self::PAGE ? ProductCursor::after($expected[self::PAGE - 1], $sort) : null;
                        ++$pages;
                    } while (null !== $after && $pages < 60);
                }
            }
        }
    }

    private function source(): DbalBackofficeProductSource
    {
        return null === $this->budget
            ? new DbalBackofficeProductSource($this->connection, new BoundedCounter($this->connection))
            : new DbalBackofficeProductSource($this->connection, new BoundedCounter($this->connection), phraseBudget: $this->budget);
    }

    private static function path(string ...$ids): CategoryPath
    {
        return CategoryPath::fromString('/'.implode('/', $ids).'/');
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

    /**
     * @param list<BackofficeProduct> $products
     *
     * @return list<string>
     */
    private function names(array $products): array
    {
        return array_map(static fn (BackofficeProduct $p): string => $p->name, $products);
    }

    private function seed(): void
    {
        $this->connection->executeStatement("INSERT INTO catalog_category (id, name, slug, path, parent_id, position, version) VALUES
            ('".self::ROOT."', 'Root', 'root', '/".self::ROOT."/', NULL, 0, 1),
            ('".self::LEFT."', 'Left', 'left', '/".self::ROOT.'/'.self::LEFT."/', '".self::ROOT."', 0, 1),
            ('".self::LEAF."', 'Leaf', 'leaf', '/".self::ROOT.'/'.self::LEFT.'/'.self::LEAF."/', '".self::LEFT."', 0, 1),
            ('".self::RIGHT."', 'Right', 'right', '/".self::RIGHT."/', NULL, 1, 1),
            ('".self::EMPTY."', 'Empty', 'empty', '/".self::EMPTY."/', NULL, 2, 1),
            ('".self::OTHER."', 'Other', 'other', '/".self::OTHER."/', NULL, 3, 1),
            ('".self::SIBLING."', 'Sibling', 'sibling', '/".self::SIBLING."/', NULL, 4, 1)");
        $mains = [self::ROOT, self::LEFT, self::LEAF, self::RIGHT, self::OTHER, self::SIBLING];
        $moments = ['2026-11-01 10:00:00', '2026-11-02 10:00:00', '2026-11-03 10:00:00'];
        $names = ['Alfa', 'Beta', 'Gamma', 'Alfa', 'Delta', 'Żółw', 'Łódź', 'Beta'];
        $number = 0;
        foreach ([1, 2, 3] as $_round) {
            foreach ($names as $name) {
                ++$number;
                $this->insertProduct($number, $name, $mains[$number % \count($mains)], 0 === $number % 5 ? '[]' : '[{"key": "originals/a.jpg", "alt": "a"}]', 0 === $number % 7 ? 'draft' : 'published', $moments[$number % 3]);
                // Every third product is also in the leaf, every fourth in the right category, every fifth in the other one.
                foreach (array_filter([0 === $number % 3 ? self::LEAF : null, 0 === $number % 4 ? self::RIGHT : null, 0 === $number % 5 ? self::OTHER : null]) as $additional) {
                    if ($additional !== $mains[$number % \count($mains)]) {
                        $this->connection->executeStatement('INSERT INTO catalog_product_category (product_id, category_id) VALUES (?, ?)', [self::productId($number), $additional]);
                    }
                }
            }
        }
        // The main category and an additional one in the same subtree, and three additional ones in it, each product once in the list of the root.
        $this->insertProduct(900, 'Podwójny', self::LEFT, '[{"key": "k", "alt": "a"}]', 'published', '2026-12-01 10:00:00');
        $this->connection->executeStatement('INSERT INTO catalog_product_category (product_id, category_id) VALUES (?, ?)', [self::productId(900), self::LEAF]);
        $this->insertProduct(901, 'Potrójny', self::OTHER, '[{"key": "k", "alt": "a"}]', 'published', '2026-12-02 10:00:00');
        foreach ([self::ROOT, self::LEFT, self::LEAF] as $additional) {
            $this->connection->executeStatement('INSERT INTO catalog_product_category (product_id, category_id) VALUES (?, ?)', [self::productId(901), $additional]);
        }
        $this->connection->executeStatement('ANALYZE catalog_product');
    }

    private static function productId(int $number): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $number);
    }

    private function insertProduct(int $number, string $name, string $category, string $images, string $status, string $updatedAt): void
    {
        $this->connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, primary_category_id, version, variant_axes, status, updated_at)
             VALUES (?, ?, ?, '', 'standard', ?::jsonb, ?, 1, '[]'::jsonb, ?, ?)",
            [self::productId($number), 'p-'.$number, $name, $images, $category, $status, $updatedAt.'+00'],
        );
    }

    // The statement of DbalBackofficeProductSource as it was before the category: the reference of the equivalence.

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
    private static function referenceFilter(CategoryPath $category, ?ProductStatus $status, bool $withoutImages): array
    {
        $conditions = [self::CATEGORY];
        $parameters = ['prefix' => strtr($category->toString(), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%'];
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
    private function referencePage(CategoryPath $category, ?ProductStatus $status, ?ProductCursor $after, ProductSort $sort, int $limit, bool $withoutImages): array
    {
        [$conditions, $parameters] = self::referenceFilter($category, $status, $withoutImages);
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

    private function referenceCount(CategoryPath $category, ?ProductStatus $status, bool $withoutImages, int $limit): BoundedCount
    {
        [$conditions, $parameters] = self::referenceFilter($category, $status, $withoutImages);

        return new BoundedCounter($this->connection)->count('catalog_product p', $conditions, $parameters, limit: $limit);
    }
}
