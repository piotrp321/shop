<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class DbalProductSearchSourceTest extends KernelTestCase
{
    use Factories;

    private ProductSearchSource $source;
    private Connection $connection;
    private DoctrineCategoryRepository $categories;
    private DoctrineProductRepository $products;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $source = $container->get(ProductSearchSource::class);
        self::assertInstanceOf(ProductSearchSource::class, $source);
        $this->source = $source;
        $entityManager = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->categories = new DoctrineCategoryRepository($entityManager);
        $this->products = new DoctrineProductRepository($entityManager);
    }

    public function testPagesThroughSevenProductsInThreeThreeOneThenEmpty(): void
    {
        $this->createProducts(7);

        self::assertSame([3, 3, 1, 0], array_map('count', $this->pages(3, 4)));
    }

    public function testPagesThroughSixProductsInThreeThreeThenEmpty(): void
    {
        $this->createProducts(6);

        self::assertSame([3, 3, 0], array_map('count', $this->pages(3, 3)));
    }

    public function testEmptyCatalogGivesAnEmptyList(): void
    {
        self::assertSame([], $this->source->page(null, 10));
    }

    public function testEveryProductAppearsExactlyOnceInAscendingIdOrder(): void
    {
        $expected = $this->createProducts(7);
        sort($expected);

        $ids = [];
        foreach ($this->pages(3, 4) as $page) {
            foreach ($page as $snapshot) {
                $ids[] = $snapshot->id;
            }
        }

        self::assertSame($expected, $ids);
    }

    public function testLimitAppliesToProductsNotToJoinedRows(): void
    {
        $main = $this->savedCategory();
        $additional = [];
        for ($i = 0; $i < 10; ++$i) {
            $additional[] = $this->savedCategory();
        }
        for ($i = 0; $i < 3; ++$i) {
            $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => $additional]));
        }
        $expectedIds = array_map(static fn (Category $c): string => $c->id()->toString(), $additional);
        sort($expectedIds);

        $page = $this->source->page(null, 2);

        self::assertCount(2, $page);
        foreach ($page as $snapshot) {
            self::assertSame($expectedIds, $snapshot->additionalCategoryIds);
            self::assertCount(11, $snapshot->categoryPaths);
        }
        self::assertCount(1, $this->source->page($page[1]->id, 2));
    }

    public function testSnapshotMatchesWhatWasSaved(): void
    {
        $root = $this->savedCategory();
        $main = $this->savedCategory($root);
        $extraB = $this->savedCategory();
        $extraA = $this->savedCategory($root);
        $product = ProductFactory::createOne([
            'mainCategory' => $main,
            'additionalCategories' => [$extraB, $extraA],
            'imageAlts' => ['front', 'back'],
            'shortDescription' => 'Short text',
        ]);
        $this->products->save($product);

        $page = $this->source->page(null, 10);

        self::assertCount(1, $page);
        $snapshot = $page[0];
        self::assertSame($product->id()->toString(), $snapshot->id);
        self::assertSame(1, $snapshot->version);
        self::assertSame($product->name()->value(), $snapshot->name);
        self::assertSame($product->slug()->value(), $snapshot->slug);
        self::assertSame('Short text', $snapshot->shortDescription);
        self::assertSame($main->id()->toString(), $snapshot->mainCategoryId);

        $additionalIds = [$extraA->id()->toString(), $extraB->id()->toString()];
        sort($additionalIds);
        self::assertSame($additionalIds, $snapshot->additionalCategoryIds);

        // Main category path first, then the additional categories in the order of additionalCategoryIds.
        $pathsById = [
            $main->id()->toString() => [$root->id()->toString(), $main->id()->toString()],
            $extraA->id()->toString() => [$root->id()->toString(), $extraA->id()->toString()],
            $extraB->id()->toString() => [$extraB->id()->toString()],
        ];
        self::assertSame(
            [$pathsById[$main->id()->toString()], ...array_map(static fn (string $id): array => $pathsById[$id], $additionalIds)],
            $snapshot->categoryPaths,
        );

        $firstImage = $product->images()->toList()[0];
        self::assertSame(['key' => $firstImage->key()->toString(), 'alt' => 'front'], $snapshot->mainImage);
    }

    public function testProductWithoutImagesHasNoMainImage(): void
    {
        $this->products->save(ProductFactory::createOne(['mainCategory' => $this->savedCategory()]));

        $page = $this->source->page(null, 10);

        self::assertNull($page[0]->mainImage);
        self::assertSame([], $page[0]->additionalCategoryIds);
        self::assertCount(1, $page[0]->categoryPaths);
    }

    public function testVersionIsReadFromTheStoredRow(): void
    {
        $product = ProductFactory::createOne(['mainCategory' => $this->savedCategory()]);
        $this->products->save($product);
        $this->connection->executeStatement('UPDATE catalog_product SET version = 7 WHERE id = ?', [$product->id()->toString()]);

        self::assertSame(7, $this->source->page(null, 1)[0]->version);
    }

    public function testRejectsALimitBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->source->page(null, 0);
    }

    public function testRejectsACursorThatIsNotAUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->source->page('abc', 10);
    }

    public function testContinuesAfterTheCursorProductWasDeleted(): void
    {
        $all = $this->createProducts(7);
        sort($all);
        $first = $this->source->page(null, 3);
        $cursor = $first[2]->id;
        $this->connection->executeStatement('DELETE FROM catalog_product WHERE id = ?', [$cursor]);

        $ids = array_map(static fn (ProductSearchSnapshot $s): string => $s->id, $first);
        foreach ($this->pages(3, 4, $cursor) as $page) {
            foreach ($page as $snapshot) {
                $ids[] = $snapshot->id;
            }
        }

        // Nothing is skipped and nothing is repeated: the deleted cursor product was already read.
        self::assertSame($all, $ids);
    }

    public function testRowsInsertedWhilePagingAreSeenOnlyWhenTheirIdIsAboveTheCursor(): void
    {
        // This pins the documented limitation of the offline rebuild (spec "Ograniczenia tej przebudowy"): keyset
        // paging by id cannot see a product inserted below the cursor, only one above it.
        $this->createProducts(6);
        $first = $this->source->page(null, 3);
        $cursor = $first[2]->id;
        $main = $this->savedCategory();
        $below = $this->saveProductWithId('00000000-0000-4000-8000-000000000000', $main);
        $above = $this->saveProductWithId('ffffffff-ffff-4fff-8fff-ffffffffffff', $main);

        $later = [];
        foreach ($this->pages(3, 4, $cursor) as $page) {
            foreach ($page as $snapshot) {
                $later[] = $snapshot->id;
            }
        }

        self::assertContains($above, $later);
        self::assertNotContains($below, $later);
        self::assertCount(4, $later);
    }

    public function testAnUpperCaseCursorGivesTheSameResultAsALowerCaseOne(): void
    {
        $this->createProducts(5);
        $cursor = $this->source->page(null, 2)[1]->id;

        $lower = $this->source->page($cursor, 10);
        $upper = $this->source->page(strtoupper($cursor), 10);

        self::assertCount(3, $lower);
        self::assertEquals($lower, $upper);
        foreach ($upper as $snapshot) {
            self::assertSame(strtolower($snapshot->id), $snapshot->id);
        }
    }

    public function testIssuesExactlyOneStatementPerPageRegardlessOfCategoryCount(): void
    {
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $main = $this->savedCategory();
        $additional = [$this->savedCategory(), $this->savedCategory(), $this->savedCategory()];
        for ($i = 0; $i < 5; ++$i) {
            $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => $i % 2 ? $additional : []]));
        }

        $after = null;
        $perPage = [];
        do {
            $before = $counter->count();
            $page = $this->source->page($after, 2);
            $perPage[] = $counter->count() - $before;
            $after = [] === $page ? null : $page[array_key_last($page)]->id;
        } while (null !== $after);

        // Pages of 2, 2, 1 products and the final empty page.
        self::assertSame([1, 1, 1, 1], $perPage);
    }

    public function testMemoryDependsOnThePageSizeNotOnTheCatalogSize(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO catalog_category (id, name, path) VALUES ('00000000-0000-4000-8000-000000000001', 'Root', '/00000000-0000-4000-8000-000000000001/')",
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_category, images, version)
                SELECT gen_random_uuid(), 'bulk-'||n, 'Bulk product '||n, repeat('x', 200), '00000000-0000-4000-8000-000000000001',
                       'standard', '[{"key": "originals/a.jpg", "alt": "front"}]'::jsonb, 1
                FROM generate_series(1, 5000) AS n
                SQL,
        );

        gc_collect_cycles();
        $startUsage = memory_get_usage();
        $peaks = [];
        $count = 0;
        $after = null;
        do {
            // The peak is reset per page so that each page's own allocation is measured, not the earlier high-water mark.
            memory_reset_peak_usage();
            $baseline = memory_get_usage();
            $page = $this->source->page($after, 500);
            $peaks[] = memory_get_peak_usage() - $baseline;
            $count += \count($page);
            $after = [] === $page ? null : $page[array_key_last($page)]->id;
            unset($page);
        } while (null !== $after);

        self::assertSame(5000, $count);
        self::assertCount(11, $peaks);
        // memory_get_usage() does not include the libpq result buffer, so this measures the PHP side of a page only.
        // A page of 500 snapshots and its raw rows measures about 1.3 MB here; 4 MB leaves room for allocator and
        // PDO buffering differences while still failing if a page ever held a large part of the 5000 products.
        self::assertLessThan(4 * 1024 * 1024, max($peaks));
        // The last pages cost no more than the first ones (small slack for allocator noise): nothing accumulates.
        self::assertLessThan(max(\array_slice($peaks, 0, 3)) + 1024 * 1024, max(\array_slice($peaks, -4)));
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $startUsage);
    }

    /**
     * @return list<list<ProductSearchSnapshot>>
     */
    private function pages(int $limit, int $maxPages, ?string $after = null): array
    {
        $pages = [];
        for ($i = 0; $i < $maxPages; ++$i) {
            $page = $this->source->page($after, $limit);
            $pages[] = $page;
            if ([] === $page) {
                break;
            }
            $after = $page[array_key_last($page)]->id;
        }

        return $pages;
    }

    /**
     * @return list<string> ids of the saved products
     */
    private function createProducts(int $count): array
    {
        $main = $this->savedCategory();
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $product = ProductFactory::createOne(['mainCategory' => $main]);
            $this->products->save($product);
            $ids[] = $product->id()->toString();
        }

        return $ids;
    }

    private function saveProductWithId(string $id, Category $main): string
    {
        $this->products->save(ProductFactory::createOne(['id' => $id, 'mainCategory' => $main]));

        return $id;
    }

    private function savedCategory(?Category $parent = null): Category
    {
        $category = CategoryFactory::createOne(['parent' => $parent]);
        $this->categories->save($category);

        return $category;
    }
}
