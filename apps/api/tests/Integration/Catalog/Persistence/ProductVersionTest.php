<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;
use Zenstruck\Foundry\Test\Factories;

final class ProductVersionTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineCategoryRepository $categories;
    private DoctrineProductRepository $products;

    /** @var list<EntityManagerInterface> */
    private array $independentManagers = [];
    /** @var list<string> */
    private array $committedProductIds = [];
    /** @var list<string> */
    private array $committedCategoryIds = [];

    protected function setUp(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->categories = new DoctrineCategoryRepository($entityManager);
        $this->products = new DoctrineProductRepository($entityManager);
    }

    protected function tearDown(): void
    {
        // Rows committed by the concurrency tests live outside the DAMA transaction, so they are removed here.
        if ([] !== $this->independentManagers) {
            $cleanup = $this->independentManagers[0]->getConnection();
            foreach ($this->committedProductIds as $id) {
                $cleanup->executeStatement('DELETE FROM catalog_product WHERE id = ?', [$id]);
            }
            foreach ($this->committedCategoryIds as $id) {
                $cleanup->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$id]);
            }
            foreach ($this->independentManagers as $manager) {
                $manager->getConnection()->close();
            }
        }
        parent::tearDown();
    }

    public function testNewProductHasVersionOne(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main]);

        $this->products->save($product);

        self::assertSame(1, $this->version($product));
    }

    public function testChangingTheNameIncrementsTheVersion(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main]);
        $this->products->save($product);
        $this->entityManager->clear();

        $this->products->save($this->changed($product, $main, [], ['name' => 'Another name']));

        self::assertSame(2, $this->version($product));
    }

    public function testChangingOnlyAdditionalCategoriesIncrementsTheVersion(): void
    {
        [$main, $a, $b] = $this->savedCategories(3);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$a]]);
        $this->products->save($product);
        $this->entityManager->clear();

        $this->products->save($this->changed($product, $main, [$a, $b]));

        self::assertSame(2, $this->version($product));
        self::assertSame($this->sortedIds([$a, $b]), $this->assignedCategoryIds($product));
    }

    public function testChangingFieldsAndCategoriesTogetherIncrementsTheVersionOnce(): void
    {
        [$main, $a, $b] = $this->savedCategories(3);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$a]]);
        $this->products->save($product);
        $this->entityManager->clear();

        $this->products->save($this->changed($product, $main, [$b], ['name' => 'Another name']));

        self::assertSame(2, $this->version($product));
        self::assertSame([$b->id()->toString()], $this->assignedCategoryIds($product));
    }

    public function testSavingUnchangedProductKeepsVersionAndAssignments(): void
    {
        [$main, $a, $b] = $this->savedCategories(3);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$b, $a], 'imageAlts' => ['front']]);
        $this->products->save($product);
        $this->entityManager->clear();

        $this->products->save($this->changed($product, $main, [$a, $b], [], ['front']));
        $this->products->save($this->changed($product, $main, [$a, $b], [], ['front']));

        self::assertSame(1, $this->version($product));
        self::assertSame($this->sortedIds([$a, $b]), $this->assignedCategoryIds($product));
    }

    public function testStaleWriteInSameUnitOfWorkThrowsAndLeavesTablesUnchangedAndRepositoryUsableAfterReset(): void
    {
        [$main, $a, $b] = $this->savedCategories(3);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$a]]);
        $this->products->save($product);
        $this->entityManager->clear();
        $this->products->get($product->id());
        // Somebody else changes the row after our read.
        $this->connection->executeStatement('UPDATE catalog_product SET version = version + 1 WHERE id = ?', [$product->id()->toString()]);

        try {
            $this->products->save($this->changed($product, $main, [$b], ['name' => 'Stale name']));
            self::fail('Expected ProductModifiedConcurrently.');
        } catch (ProductModifiedConcurrently) {
        }

        self::assertFalse($this->entityManager->isOpen());
        self::assertSame(2, $this->version($product));
        self::assertSame([$a->id()->toString()], $this->assignedCategoryIds($product));
        self::assertNotSame('Stale name', $this->connection->fetchOne('SELECT name FROM catalog_product WHERE id = ?', [$product->id()->toString()]));

        // The kernel resets services between requests and Messenger between messages (kernel.reset).
        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        $next = ProductFactory::createOne(['mainCategory' => $main]);
        $this->products->save($next);
        self::assertSame($next->slug()->value(), $this->products->get($next->id())->slug()->value());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function secondWriterChanges(): iterable
    {
        yield 'only additional categories' => [false];
        yield 'name and additional categories' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secondWriterChanges')]
    public function testConcurrentWriteBasedOnStaleReadIsRejectedWithoutChangingTables(bool $secondChangesName): void
    {
        $first = $this->independentManager();
        $second = $this->independentManager();
        $firstProducts = new DoctrineProductRepository($first);
        $secondProducts = new DoctrineProductRepository($second);

        $categories = new DoctrineCategoryRepository($first);
        $categoryList = CategoryFactory::createMany(4);
        foreach ($categoryList as $category) {
            $categories->save($category);
            $this->committedCategoryIds[] = $category->id()->toString();
        }
        [$main, $a, $b, $c] = $categoryList;
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$a]]);
        $this->committedProductIds[] = $product->id()->toString();
        $firstProducts->save($product);
        $first->clear();

        // Both writers read version 1.
        $firstProducts->get($product->id());
        $secondProducts->get($product->id());
        self::assertSame(1, $this->committedVersion($first, $product));

        // The first writer commits a change of additional categories.
        $firstProducts->save($this->changed($product, $main, [$a, $b]));
        self::assertSame(2, $this->committedVersion($first, $product));

        // The second writer saves a different change based on version 1.
        $overrides = $secondChangesName ? ['name' => 'Second writer name'] : [];
        try {
            $secondProducts->save($this->changed($product, $main, [$c], $overrides));
            self::fail('Expected ProductModifiedConcurrently.');
        } catch (ProductModifiedConcurrently) {
        }

        // A third connection sees only the first writer's state.
        $verify = $this->independentManager()->getConnection();
        self::assertSame(2, $verify->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$product->id()->toString()]));
        self::assertSame($product->name()->value(), $verify->fetchOne('SELECT name FROM catalog_product WHERE id = ?', [$product->id()->toString()]));
        self::assertSame(
            $this->sortedIds([$a, $b]),
            $verify->fetchFirstColumn('SELECT category_id FROM catalog_product_category WHERE product_id = ? ORDER BY category_id', [$product->id()->toString()]),
        );

        // After the conflict the next repository operation works once the closed manager is replaced (kernel.reset).
        self::assertFalse($second->isOpen());
        $fresh = new DoctrineProductRepository($this->independentManager());
        self::assertSame($product->slug()->value(), $fresh->get($product->id())->slug()->value());
        $fresh->save($this->changed($product, $main, [$a, $b, $c]));
        self::assertSame(3, $this->committedVersion($first, $product));
    }

    private function independentManager(): EntityManagerInterface
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $kernelManager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $kernelManager);

        // A plain DBAL configuration, so the DAMA static connection driver is not applied.
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelManager->getConnection()->getParams();
        $connection = DriverManager::getConnection($params, new DbalConfiguration());
        $manager = new EntityManager($connection, $kernelManager->getConfiguration());
        $this->independentManagers[] = $manager;

        return $manager;
    }

    private function committedVersion(EntityManagerInterface $manager, Product $product): int
    {
        $version = $manager->getConnection()->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$product->id()->toString()]);
        \assert(\is_int($version));

        return $version;
    }

    /**
     * The same product (id and slug) with a new state.
     *
     * @param list<Category>       $additional
     * @param array<string, mixed> $overrides
     * @param list<string>         $imageAlts
     */
    private function changed(Product $product, Category $main, array $additional, array $overrides = [], array $imageAlts = []): Product
    {
        return ProductFactory::createOne([
            'id' => $product->id()->toString(),
            'slug' => $product->slug()->value(),
            'name' => $product->name()->value(),
            'mainCategory' => $main,
            'additionalCategories' => $additional,
            'imageAlts' => $imageAlts,
            ...$overrides,
        ]);
    }

    private function version(Product $product): int
    {
        return $this->committedVersion($this->entityManager, $product);
    }

    /**
     * @param list<Category> $categories
     *
     * @return list<string>
     */
    private function sortedIds(array $categories): array
    {
        $ids = array_map(static fn (Category $category): string => $category->id()->toString(), $categories);
        sort($ids);

        return $ids;
    }

    /**
     * @return list<Category>
     */
    private function savedCategories(int $count): array
    {
        $categories = CategoryFactory::createMany($count);
        foreach ($categories as $category) {
            $this->categories->save($category);
        }

        return $categories;
    }

    /**
     * @return list<string>
     */
    private function assignedCategoryIds(Product $product): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT category_id FROM catalog_product_category WHERE product_id = ? ORDER BY category_id',
            [$product->id()->toString()],
        );
    }
}
