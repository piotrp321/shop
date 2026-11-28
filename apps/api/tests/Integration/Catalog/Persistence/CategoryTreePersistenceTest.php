<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Category\CategorySlugPath;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalCategoryProductCounts;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRedirectRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\PostgresCategoryTreeLock;
use App\Shared\Domain\BoundedCount;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The adapters of the category tree on PostgreSQL (shop_test, the DAMA transaction rolled back after each test):
 * positions and versions, the paths of a moved subtree in one statement with the records of the identity map
 * refreshed, the redirects with their cascade, the lock of the tree and the bounded product counts.
 */
final class CategoryTreePersistenceTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineCategoryRepository $categories;
    private DoctrineCategoryRedirectRepository $redirects;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->categories = new DoctrineCategoryRepository($entityManager);
        $this->redirects = new DoctrineCategoryRedirectRepository($entityManager);
    }

    public function testPositionAndVersionAreSavedAndReadBack(): void
    {
        $root = CategoryFactory::createOne();
        $this->categories->save($root);
        $root->placeAt(3);
        $root->revise(CategoryName::of('Nowa'), new CategorySlug('nowa'));
        $this->categories->saveAll([$root]);
        $this->entityManager->clear();

        $loaded = $this->categories->get($root->id());

        self::assertSame(3, $loaded->position());
        self::assertSame(2, $loaded->version());
        self::assertSame('nowa', $loaded->slug()->value());
    }

    public function testMovingPathsChangesTheCategoryAndItsDescendantsAndRefreshesLoadedRecords(): void
    {
        [$sport, $run, $shoes, $tourism] = $this->sportTree();
        $this->categories->all();
        $before = $this->statements();

        $run->moveUnder($tourism, 0);
        $this->categories->movePaths(CategoryPath::fromString('/'.$sport->id()->toString().'/'.$run->id()->toString().'/'), $run->path());

        self::assertSame(1, $this->statements() - $before, 'One UPDATE for the whole subtree.');
        $paths = $this->connection->fetchAllKeyValue('SELECT id, path FROM catalog_category ORDER BY path');
        self::assertSame('/'.$tourism->id()->toString().'/'.$run->id()->toString().'/', $paths[$run->id()->toString()]);
        self::assertSame('/'.$tourism->id()->toString().'/'.$run->id()->toString().'/'.$shoes->id()->toString().'/', $paths[$shoes->id()->toString()]);
        self::assertSame('/'.$sport->id()->toString().'/', $paths[$sport->id()->toString()]);
        // The records the ORM holds see the new paths, without a clear.
        self::assertSame($paths[$shoes->id()->toString()], $this->categories->get($shoes->id())->path()->toString());

        $this->categories->saveAll([$run]);
        self::assertSame($tourism->id()->toString(), $this->connection->fetchOne('SELECT parent_id FROM catalog_category WHERE id = ?', [$run->id()->toString()]));
    }

    public function testAllReadsWhatAnotherTransactionCommittedAfterAnEarlierRead(): void
    {
        $other = $this->secondConnection();
        $id = '00000000-0000-4000-8000-0000000000aa';
        $other->executeStatement("INSERT INTO catalog_category (id, name, slug, path) VALUES (?, 'Zima', 'zima-aa', ?)", [$id, '/'.$id.'/']);
        try {
            self::assertSame('Zima', $this->find($id)->name()->value());

            $other->executeStatement("UPDATE catalog_category SET name = 'Lato', position = 4, version = 7 WHERE id = ?", [$id]);

            $fresh = $this->find($id);
            self::assertSame('Lato', $fresh->name()->value());
            self::assertSame(4, $fresh->position());
            self::assertSame(7, $fresh->version());
        } finally {
            $other->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$id]);
            $other->close();
        }
    }

    public function testARemovedCategoryTakesItsRedirectsWithIt(): void
    {
        [, $run, $shoes] = $this->sportTree();
        $this->redirects->record(['sport/bieganie' => $run->id(), 'sport/biegi/buty' => $shoes->id()]);

        $this->categories->remove($shoes);

        self::assertNull($this->redirects->find(CategorySlugPath::fromString('sport/biegi/buty')));
        self::assertSame($run->id()->toString(), $this->redirects->find(CategorySlugPath::fromString('sport/bieganie'))?->toString());
        $this->expectException(CategoryNotFound::class);
        $this->categories->get($shoes->id());
    }

    public function testARedirectRecordedAgainLeadsToTheNewCategoryAndAForgottenOneIsGone(): void
    {
        [$sport, $run] = $this->sportTree();
        $this->redirects->record(['stare' => $sport->id(), 'inne' => $sport->id()]);

        $this->redirects->record(['stare' => $run->id()]);
        $this->redirects->forget(['inne', 'nieznane']);

        self::assertSame($run->id()->toString(), $this->redirects->find(CategorySlugPath::fromString('stare'))?->toString());
        self::assertNull($this->redirects->find(CategorySlugPath::fromString('inne')));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM catalog_category_redirect'));
    }

    public function testTheLockOfTheTreeIsHeldUntilTheEndOfTheTransaction(): void
    {
        $other = $this->secondConnection();
        $this->connection->beginTransaction();
        try {
            new PostgresCategoryTreeLock($this->connection)->exclusive();

            self::assertFalse($other->fetchOne('SELECT pg_try_advisory_xact_lock(CAST(? AS bigint))', [PostgresCategoryTreeLock::KEY]));
        } finally {
            $this->connection->rollBack();
        }
        try {
            self::assertTrue($other->fetchOne('SELECT pg_try_advisory_lock(CAST(? AS bigint))', [PostgresCategoryTreeLock::KEY]));
        } finally {
            $other->close();
        }
    }

    public function testTheLockOfTheTreeRefusesToWorkOutsideATransaction(): void
    {
        $other = $this->secondConnection();
        try {
            $this->expectException(\LogicException::class);
            new PostgresCategoryTreeLock($other)->exclusive();
        } finally {
            $other->close();
        }
    }

    public function testProductCountsAreDirectMainOrAdditionalDraftsTooAndBounded(): void
    {
        [$sport, $run, $shoes] = $this->sportTree();
        $products = new DoctrineProductRepository($this->entityManager);
        $products->save(ProductFactory::createOne(['mainCategory' => $run]), null, null, new \DateTimeImmutable());
        $products->save(ProductFactory::createOne(['mainCategory' => $run, 'status' => ProductStatus::Draft]), null, null, new \DateTimeImmutable());
        $products->save(ProductFactory::createOne(['mainCategory' => $shoes, 'additionalCategories' => [$run]]), null, null, new \DateTimeImmutable());
        $counts = new DbalCategoryProductCounts($this->connection);

        self::assertEquals(new BoundedCount(3, false), $counts->count($run->id(), 1000));
        self::assertEquals(new BoundedCount(2, true), $counts->count($run->id(), 2));
        self::assertEquals(new BoundedCount(1, false), $counts->count($shoes->id(), 1000));
        self::assertEquals(new BoundedCount(0, false), $counts->count($sport->id(), 1000));
        self::assertEquals([
            $run->id()->toString() => new BoundedCount(2, true),
            $shoes->id()->toString() => new BoundedCount(1, false),
        ], $counts->countAll(2));
    }

    public function testLockingARowOfAnUnknownCategoryIsHarmless(): void
    {
        $this->categories->lock(CategoryId::fromString('00000000-0000-4000-8000-0000000000ff'));

        self::assertSame(1, $this->connection->fetchOne('SELECT 1'));
    }

    /**
     * @return array{Category, Category, Category, Category} sport, sport/bieganie, sport/bieganie/buty, turystyka
     */
    private function sportTree(): array
    {
        $sport = CategoryFactory::createOne(['slug' => 'sport', 'name' => 'Sport']);
        $run = CategoryFactory::createOne(['slug' => 'bieganie', 'name' => 'Bieganie', 'parent' => $sport]);
        $shoes = CategoryFactory::createOne(['slug' => 'buty', 'name' => 'Buty', 'parent' => $run]);
        $tourism = CategoryFactory::createOne(['slug' => 'turystyka', 'name' => 'Turystyka']);
        $this->categories->saveAll([$sport, $run, $shoes, $tourism]);

        return [$sport, $run, $shoes, $tourism];
    }

    private function find(string $id): Category
    {
        foreach ($this->categories->all() as $category) {
            if ($category->id()->toString() === $id) {
                return $category;
            }
        }
        self::fail('The category is not read.');
    }

    private function statements(): int
    {
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);

        return $counter->writes();
    }

    private function secondConnection(): Connection
    {
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection->getParams();
        $connection = DriverManager::getConnection($params, new DbalConfiguration());
        $connection->executeStatement("SET lock_timeout = '10s'");

        return $connection;
    }
}
