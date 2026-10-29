<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
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

    /**
     * @var resource|null
     */
    private $lockHolder;
    /** @var array<int, resource> */
    private array $lockHolderPipes = [];

    protected function tearDown(): void
    {
        $this->stopLockHolder();
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

    public function testChangingAnImageAltBetweenNumericallyEqualStringsIsPersisted(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'imageAlts' => ['10']]);
        $this->products->save($product);
        $this->entityManager->clear();

        $this->products->save($this->changed($product, $main, [], [], ['1e1']));

        self::assertSame(2, $this->version($product));
        self::assertSame('1e1', $this->products->get($product->id())->images()->toList()[0]->alt());
        $this->entityManager->clear();
        self::assertSame('1e1', $this->products->get($product->id())->images()->toList()[0]->alt());
    }

    public function testSavingUnchangedProductKeepsVersionAndAssignments(): void
    {
        [$main, $a, $b] = $this->savedCategories(3);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$b, $a], 'imageAlts' => ['front']]);
        $this->products->save($product);
        $this->entityManager->clear();

        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $writesBefore = $counter->writes();

        $this->products->save($this->changed($product, $main, [$a, $b], [], ['front']));
        $this->products->save($this->changed($product, $main, [$a, $b], [], ['front']));

        // Reads are expected; an unchanged save must not write anything, not even a version bump.
        self::assertSame(0, $counter->writes() - $writesBefore);
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

    public function testFailedAdditionalCategoryWriteRollsBackTheVersionIncrement(): void
    {
        [$main, $a] = $this->savedCategories(2);
        $missing = CategoryFactory::createOne(); // never saved, so the foreign key rejects it
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$a]]);
        $this->products->save($product);
        $this->entityManager->clear();

        try {
            // Only the additional categories change, so the manual version UPDATE runs before the flush fails.
            $this->products->save($this->changed($product, $main, [$missing]));
            self::fail('Expected a foreign key violation.');
        } catch (DbalException) {
        }

        self::assertSame(1, $this->version($product));
        self::assertSame([$a->id()->toString()], $this->assignedCategoryIds($product));
    }

    public function testConflictInsideAnOuterTransactionReachesTheCallerAndStoresNothing(): void
    {
        $first = $this->independentManager();
        $second = $this->independentManager();
        $firstProducts = new DoctrineProductRepository($first);
        $secondProducts = new DoctrineProductRepository($second);
        [$main, $a, $b, $c] = $this->committedCategories($first, 4);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$a]]);
        $this->committedProductIds[] = $product->id()->toString();
        $firstProducts->save($product);
        $first->clear();
        $secondProducts->get($product->id());
        $firstProducts->save($this->changed($product, $main, [$a, $b]));

        try {
            // As a handler on command.bus with doctrine_transaction: the save runs inside an outer transaction.
            $second->wrapInTransaction(fn () => $secondProducts->save($this->changed($product, $main, [$c], ['name' => 'Stale name'])));
            self::fail('Expected ProductModifiedConcurrently.');
        } catch (ProductModifiedConcurrently) {
        }

        $verify = $this->independentManager()->getConnection();
        $id = $product->id()->toString();
        self::assertSame(2, $verify->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$id]));
        self::assertSame($product->name()->value(), $verify->fetchOne('SELECT name FROM catalog_product WHERE id = ?', [$id]));
        self::assertSame($this->sortedIds([$a, $b]), $verify->fetchFirstColumn('SELECT category_id FROM catalog_product_category WHERE product_id = ? ORDER BY category_id', [$id]));
        self::assertFalse($second->getConnection()->isTransactionActive());
    }

    public function testChangingOnlyTheImageOrderIncrementsTheVersionAndPersistsTheOrder(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'imageAlts' => ['front', 'back']]);
        $this->products->save($product);
        $this->entityManager->clear();
        $reversed = array_reverse($product->images()->toList());
        $reordered = Product::reconstitute(
            $product->id(),
            $product->name(),
            $product->slug(),
            $product->shortDescription(),
            $product->categories(),
            $product->taxCategory(),
            ProductImages::fromList(array_map(
                static fn (ProductImage $image, int $position): ProductImage => new ProductImage($image->key(), $image->alt(), $position),
                $reversed,
                array_keys($reversed),
            )),
        );

        $this->products->save($reordered);

        self::assertSame(2, $this->version($product));
        $this->entityManager->clear();
        $stored = $this->products->get($product->id())->images()->toList();
        self::assertSame(['back', 'front'], array_map(static fn (ProductImage $image): string => $image->alt(), $stored));
        self::assertSame(
            array_map(static fn (ProductImage $image): string => $image->key()->toString(), $reversed),
            array_map(static fn (ProductImage $image): string => $image->key()->toString(), $stored),
        );
    }

    public function testSwappingTheMainAndAnAdditionalCategoryIncrementsTheVersionOnce(): void
    {
        [$a, $b] = $this->savedCategories(2);
        $product = ProductFactory::createOne(['mainCategory' => $a, 'additionalCategories' => [$b]]);
        $this->products->save($product);
        $this->entityManager->clear();

        $this->products->save($this->changed($product, $b, [$a]));

        self::assertSame(2, $this->version($product));
        self::assertSame([$a->id()->toString()], $this->assignedCategoryIds($product));
        self::assertSame(
            $b->id()->toString(),
            $this->connection->fetchOne('SELECT primary_category_id FROM catalog_product WHERE id = ?', [$product->id()->toString()]),
        );
    }

    public function testSaveWaitingForARowLockHeldByAnotherProcessEndsInAConflict(): void
    {
        $manager = $this->independentManager();
        $repository = new DoctrineProductRepository($manager);
        [$main, $a, $b] = $this->committedCategories($manager, 3);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$a]]);
        $this->committedProductIds[] = $product->id()->toString();
        $repository->save($product);
        $manager->clear();
        // The repository under test reads version 1 before the other process writes.
        $repository->get($product->id());
        // A lost lock never hangs the suite: the waiting UPDATE gives up after 10 seconds.
        $manager->getConnection()->executeStatement("SET lock_timeout = '10s'");

        $holdMilliseconds = 700;
        $this->startLockHolder($product->id()->toString(), $holdMilliseconds);

        $started = hrtime(true);
        try {
            $repository->save($this->changed($product, $main, [$a, $b], ['name' => 'Repository writer name']));
            self::fail('Expected ProductModifiedConcurrently.');
        } catch (ProductModifiedConcurrently) {
        }
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        // The UPDATE had to wait for the other transaction instead of overwriting it.
        self::assertGreaterThanOrEqual($holdMilliseconds - 300, $elapsedMilliseconds);
        $this->finishLockHolder();

        $verify = $this->independentManager()->getConnection();
        $id = $product->id()->toString();
        self::assertSame(2, $verify->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$id]));
        self::assertSame('Held by another process', $verify->fetchOne('SELECT name FROM catalog_product WHERE id = ?', [$id]));
        self::assertSame([$a->id()->toString()], $verify->fetchFirstColumn('SELECT category_id FROM catalog_product_category WHERE product_id = ?', [$id]));
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

    /**
     * Starts a separate PHP process that updates the product row in a transaction, reports "locked" and commits
     * after $holdMilliseconds. Returns once the row lock is held.
     */
    private function startLockHolder(string $productId, int $holdMilliseconds): void
    {
        /** @var array{host: string, port?: int, dbname: string, user: string, password: string} $params */
        $params = $this->connection->getParams();
        $script = <<<'PHP'
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('HOLDER_HOST'), getenv('HOLDER_PORT'), getenv('HOLDER_DB')),
                getenv('HOLDER_USER'),
                getenv('HOLDER_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->beginTransaction();
            $update = $pdo->prepare("UPDATE catalog_product SET name = 'Held by another process', version = version + 1 WHERE id = ?");
            $update->execute([getenv('HOLDER_PRODUCT')]);
            fwrite(STDOUT, "locked\n");
            fflush(STDOUT);
            usleep((int) getenv('HOLDER_HOLD_MS') * 1000);
            $pdo->commit();
            fwrite(STDOUT, "committed\n");
            PHP;
        $environment = [
            'HOLDER_HOST' => $params['host'],
            'HOLDER_PORT' => (string) ($params['port'] ?? 5432),
            'HOLDER_DB' => $params['dbname'],
            'HOLDER_USER' => $params['user'],
            'HOLDER_PASSWORD' => $params['password'],
            'HOLDER_PRODUCT' => $productId,
            'HOLDER_HOLD_MS' => (string) $holdMilliseconds,
        ];
        $process = proc_open([\PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->lockHolderPipes, null, $environment);
        self::assertIsResource($process);
        $this->lockHolder = $process;

        $line = $this->readLine($this->lockHolderPipes[1], 10);
        if ("locked\n" !== $line) {
            $this->stopLockHolder();
            self::fail('The lock holder did not take the lock.');
        }
    }

    private function finishLockHolder(): void
    {
        $line = $this->readLine($this->lockHolderPipes[1], 10);
        $this->stopLockHolder();
        self::assertSame("committed\n", $line, 'The lock holder did not commit.');
    }

    private function stopLockHolder(): void
    {
        if (null === $this->lockHolder) {
            return;
        }
        $status = proc_get_status($this->lockHolder);
        if ($status['running']) {
            proc_terminate($this->lockHolder);
        }
        foreach ($this->lockHolderPipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->lockHolder);
        $this->lockHolder = null;
        $this->lockHolderPipes = [];
    }

    /**
     * @param resource $pipe
     */
    private function readLine($pipe, int $timeoutSeconds): string|false
    {
        $read = [$pipe];
        $write = $except = null;
        if (1 !== stream_select($read, $write, $except, $timeoutSeconds)) {
            return false;
        }

        return fgets($pipe);
    }

    /**
     * @return list<Category>
     */
    private function committedCategories(EntityManagerInterface $manager, int $count): array
    {
        $repository = new DoctrineCategoryRepository($manager);
        $list = CategoryFactory::createMany($count);
        foreach ($list as $category) {
            $repository->save($category);
            $this->committedCategoryIds[] = $category->id()->toString();
        }

        return $list;
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
