<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Application\Query\SitemapChunkKey;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalSitemapProductSource;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Unit\Ordering\SpyLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The limit of a chunk read: a range is read up to limit + 1 rows, drafts included, before the published ones are kept.
 */
final class DbalSitemapProductSourceTest extends KernelTestCase
{
    use Factories;

    private const int LIMIT = 4;

    private Connection $connection;
    private DoctrineProductRepository $products;
    private Category $category;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->products = new DoctrineProductRepository($entityManager);
        $this->category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($this->category);
    }

    public function testARangeOverTheLimitReadsAtMostOneRowMoreAndWritesAWarning(): void
    {
        // 9 products in the range, 2 and 4 are drafts: the first 4 rows (the limit) hold the published 1 and 3.
        $this->createProducts(9, [2, 4]);
        $logger = new SpyLogger();
        $rowsRead = 0;
        $spy = $this->createStub(Connection::class);
        $spy->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params = [], array $types = []) use (&$rowsRead): array {
            /** @var array<string, mixed> $params */
            /** @var array<string, ParameterType> $types */
            $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
            $rowsRead += \count($rows);

            return $rows;
        });

        $items = $this->source($logger, $spy)->publishedInRange(SitemapChunkKey::between(null, null));

        self::assertSame(self::LIMIT + 1, $rowsRead);
        self::assertSame(['product-1', 'product-3'], array_column($this->rows($items), 'slug'));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
    }

    public function testARangeWithExactlyTheLimitOfProductsWritesNoWarning(): void
    {
        $this->createProducts(self::LIMIT, [1]);
        $logger = new SpyLogger();

        $items = $this->source($logger, $this->connection)->publishedInRange(SitemapChunkKey::between(null, null));

        self::assertCount(self::LIMIT - 1, $items);
        self::assertSame([], $logger->records);
    }

    public function testARangeOfOnlyDraftsOverTheLimitStillWritesAWarning(): void
    {
        $this->createProducts(self::LIMIT + 3, range(1, self::LIMIT + 3));
        $logger = new SpyLogger();

        $items = $this->source($logger, $this->connection)->publishedInRange(SitemapChunkKey::between(null, null));

        self::assertSame([], $items);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
    }

    public function testTheRangeIsHalfOpenAndInIdOrder(): void
    {
        $this->createProducts(6, []);

        $items = $this->source(new SpyLogger(), $this->connection)->publishedInRange(SitemapChunkKey::between(self::id(2), self::id(5)));

        self::assertSame(['product-2', 'product-3', 'product-4'], array_column($this->rows($items), 'slug'));
    }

    public function testTheBoundariesAreTheIdsAfterEveryChunkSizeOfAllProductsDraftsIncluded(): void
    {
        $this->createProducts(7, [4]);

        self::assertSame([self::id(4), self::id(7)], $this->source(new SpyLogger(), $this->connection)->boundaries(3));
        self::assertSame([self::id(3), self::id(5), self::id(7)], $this->source(new SpyLogger(), $this->connection)->boundaries(2));
        self::assertSame([], $this->source(new SpyLogger(), $this->connection)->boundaries(7));
        self::assertSame([self::id(2), self::id(3), self::id(4), self::id(5), self::id(6), self::id(7)], $this->source(new SpyLogger(), $this->connection)->boundaries(1));
    }

    private function source(SpyLogger $logger, Connection $connection): DbalSitemapProductSource
    {
        return new DbalSitemapProductSource($connection, self::LIMIT, $logger);
    }

    /**
     * @param list<int> $drafts numbers of the products that are drafts
     */
    private function createProducts(int $count, array $drafts): void
    {
        for ($number = 1; $number <= $count; ++$number) {
            $product = ProductFactory::createOne([
                'id' => self::id($number),
                'slug' => 'product-'.$number,
                'mainCategory' => $this->category,
                'status' => \in_array($number, $drafts, true) ? ProductStatus::Draft : ProductStatus::Published,
            ]);
            $this->products->save($product, null, null, new \DateTimeImmutable());
        }
    }

    /**
     * @param list<array{slug: string, updatedAt: \DateTimeImmutable}> $items
     *
     * @return list<array{slug: string}>
     */
    private function rows(array $items): array
    {
        return array_map(static fn (array $item): array => ['slug' => $item['slug']], $items);
    }

    private static function id(int $number): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
