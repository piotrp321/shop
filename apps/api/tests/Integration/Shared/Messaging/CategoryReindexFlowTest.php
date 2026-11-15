<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Catalog\Application\Command\MoveCategory;
use App\Catalog\Application\Command\ReviseCategory;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ElasticsearchSubtreeProductIds;
use App\Shared\Infrastructure\Http\Cache\HttpStorefrontCache;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Integration\Catalog\Search\UsesTemporarySearchAlias;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Criterion 1 of ADMIN-FEATURES-11 on real infrastructure: a rename and a move of a category → outbox (Postgres) →
 * relay → RabbitMQ → the consumer of CategoryTreeChanged → the chain of batches on the Doctrine transport `jobs` →
 * Elasticsearch (a temporary alias). The batches have 2 products in the tests (services.yaml), so the 3 products of the
 * subtree take two batches and the empty third one. The store's cache is a spy.
 *
 * The tree: Sport > Buty > Biegowe, and Dom. In the subtree of Buty: one product in Buty, one in Biegowe, one in Dom
 * with Biegowe as an additional category. Outside: one product in Sport, one in Dom.
 */
final class CategoryReindexFlowTest extends MessagingKernelTestCase
{
    use Factories;
    use UsesTemporarySearchAlias;

    private Category $sport;
    private Category $shoes;
    private Category $running;
    private Category $home;
    /** @var array<string, string> name => product id */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->maintenance()->setup();
        $container = self::getContainer();
        $container->set(ElasticsearchProductIndexer::class, new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias()));
        $container->set(ElasticsearchSubtreeProductIds::class, new ElasticsearchSubtreeProductIds(self::clientFactory()->create(2.0), $this->alias()));
        $container->set(HttpStorefrontCache::class, new SpyStorefrontCache());

        $this->sport = CategoryFactory::createOne(['name' => 'Sport']);
        $this->shoes = CategoryFactory::createOne(['name' => 'Buty', 'parent' => $this->sport]);
        $this->running = CategoryFactory::createOne(['name' => 'Biegowe', 'parent' => $this->shoes]);
        $this->home = CategoryFactory::createOne(['name' => 'Dom']);
        $categories = new DoctrineCategoryRepository($this->entityManager());
        foreach ([$this->sport, $this->shoes, $this->running, $this->home] as $category) {
            $categories->save($category);
        }
        $this->product('in shoes', $this->shoes);
        $this->product('in running', $this->running);
        $this->product('in home, also running', $this->home, [$this->running]);
        $this->product('in sport', $this->sport);
        $this->product('in home', $this->home);
        $this->entityManager()->flush();

        $indexing = $container->get(ProductIndexing::class);
        self::assertInstanceOf(ProductIndexing::class, $indexing);
        foreach ($this->products as $productId) {
            $indexing->reindex($productId);
        }
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
    }

    public function testARenameReindexesExactlyTheProductsOfTheSubtreeWithTheNewNames(): void
    {
        $outside = $this->versions('in sport', 'in home');

        $this->dispatch(new ReviseCategory($this->id($this->shoes), 1, 'Obuwie', 'obuwie'));
        $this->deliverTheChange();
        $batches = $this->runTheChain();

        self::assertSame(3, $batches, 'two batches of the 3 products and the empty one');
        self::assertSame(['Sport', 'Obuwie'], $this->stored('in shoes')['category_names']);
        self::assertSame(['Sport', 'Obuwie', 'Biegowe'], $this->stored('in running')['category_names']);
        // Ordered by the paths, and Dom's random id may come before or after Sport's.
        self::assertEqualsCanonicalizing(['Dom', 'Sport', 'Obuwie', 'Biegowe'], $this->stored('in home, also running')['category_names']);
        self::assertSame($outside, $this->versions('in sport', 'in home'), 'the products outside the subtree are not rewritten');
        $this->assertTheRunFinished();
    }

    public function testAMoveReindexesExactlyTheProductsOfTheSubtreeWithTheNewAncestors(): void
    {
        $outside = $this->versions('in shoes', 'in sport', 'in home');

        $this->dispatch(new MoveCategory($this->id($this->running), 1, $this->id($this->home), 0));
        $this->deliverTheChange();
        $batches = $this->runTheChain();

        self::assertSame(2, $batches, 'one batch of the 2 products and the empty one');
        $home = $this->id($this->home);
        $running = $this->id($this->running);
        self::assertSame([$home, $running], $this->stored('in running')['category_ancestor_ids']);
        self::assertSame(['Dom', 'Biegowe'], $this->stored('in running')['category_names']);
        self::assertSame([$home, $running], $this->stored('in home, also running')['category_ancestor_ids']);
        self::assertSame(['Dom', 'Biegowe'], $this->stored('in home, also running')['category_names']);
        self::assertSame($outside, $this->versions('in shoes', 'in sport', 'in home'), 'the products outside the subtree are not rewritten');
        $this->assertTheRunFinished();
    }

    public function testANewOrderAmongTheSameSiblingsReindexesNothing(): void
    {
        $this->dispatch(new MoveCategory($this->id($this->home), 1, null, 0));
        $this->deliverTheChange();

        self::assertSame(0, $this->rows('jobs'));
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM catalog_category_reindex'));
    }

    private function deliverTheChange(): void
    {
        $this->consume('outbox');
        $this->consume('events');
        self::assertSame(['tags:catalog'], $this->cache()->calls, 'the change itself invalidates the catalog');
        self::assertSame(0, $this->rows('failed'));
    }

    /**
     * Consumes `jobs` until it is empty; at most 10 batches.
     *
     * @return int the batches consumed
     */
    private function runTheChain(): int
    {
        $batches = 0;
        while ($this->rows('jobs') > 0 && $batches < 10) {
            $this->consume('jobs');
            ++$batches;
            self::assertSame(0, $this->rows('failed'));
        }
        self::esClient()->indices()->refresh(['index' => $this->alias()]);

        return $batches;
    }

    private function assertTheRunFinished(): void
    {
        self::assertSame(0, $this->rows('jobs'));
        $runs = $this->connection()->fetchAllAssociative('SELECT category_id, finished_at FROM catalog_category_reindex');
        self::assertCount(1, $runs);
        self::assertNotNull($runs[0]['finished_at']);
        self::assertSame(['tags:catalog', 'tags:catalog'], $this->cache()->calls, 'the end of the chain invalidates the catalog again');
    }

    private function dispatch(object $command): void
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch($command);
    }

    /**
     * @param list<Category> $additional
     */
    private function product(string $name, Category $main, array $additional = []): void
    {
        $product = ProductFactory::createOne(['name' => $name, 'mainCategory' => $main, 'additionalCategories' => $additional]);
        new DoctrineProductRepository($this->entityManager())->save($product, null, null, new \DateTimeImmutable());
        $this->products[$name] = $product->id()->toString();
    }

    /**
     * @return array<mixed>
     */
    private function stored(string $name): array
    {
        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => $this->products[$name]]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        self::assertIsArray($source);

        return $source;
    }

    /**
     * @return array<string, int> name => the version of the stored document
     */
    private function versions(string ...$names): array
    {
        $versions = [];
        foreach ($names as $name) {
            $version = $this->stored($name)['version'];
            self::assertIsInt($version);
            $versions[$name] = $version;
        }

        return $versions;
    }

    private function id(Category $category): string
    {
        return $category->id()->toString();
    }

    private function cache(): SpyStorefrontCache
    {
        $cache = self::getContainer()->get(HttpStorefrontCache::class);
        self::assertInstanceOf(SpyStorefrontCache::class, $cache);

        return $cache;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
