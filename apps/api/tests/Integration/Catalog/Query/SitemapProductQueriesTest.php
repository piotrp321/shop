<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Query;

use App\Catalog\Application\Query\InvalidSitemapChunk;
use App\Catalog\Application\Query\ListSitemapProductChunks;
use App\Catalog\Application\Query\ListSitemapProducts;
use App\Catalog\Application\Query\SitemapChunk;
use App\Catalog\Application\Query\SitemapChunkKey;
use App\Catalog\Application\Query\SitemapChunkList;
use App\Catalog\Application\Query\SitemapProduct;
use App\Catalog\Application\Query\SitemapProductList;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\StatementCounter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * The sitemap queries on shop_test (rolled back after each test). The test configuration cuts chunks of 3 products
 * (`app.sitemap.chunk_size`) and reads at most 10 rows of a range (`app.sitemap.row_limit`).
 */
final class SitemapProductQueriesTest extends KernelTestCase
{
    use Factories;

    private MessageBusInterface $queryBus;
    private DoctrineProductRepository $products;
    private Category $category;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->products = new DoctrineProductRepository($entityManager);
        $this->category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($this->category);
    }

    public function testSevenProductsGiveChunksOfProductsOneToThreeFourToSixAndSeven(): void
    {
        $this->createProducts(7);

        self::assertSame([[1, 2, 3], [4, 5, 6], [7]], $this->numbersByChunk());
    }

    public function testExactlyAsManyProductsAsTheChunkSizeGiveOneChunk(): void
    {
        $this->createProducts(3);

        self::assertSame([[1, 2, 3]], $this->numbersByChunk());
    }

    public function testChunkEndsAreTheIdsAtPositionsAfterEveryChunkSize(): void
    {
        $this->createProducts(7);

        $ends = array_map(static fn (SitemapChunkKey $key): array => [$key->from, $key->to], $this->chunkKeys());

        self::assertSame([
            [null, self::id(4)],
            [self::id(4), self::id(7)],
            [self::id(7), null],
        ], $ends);
    }

    public function testTheFirstChunkHasAnOpenStartAndTheLastAnOpenEnd(): void
    {
        $this->createProducts(7);

        $keys = $this->chunkKeys();

        self::assertCount(3, $keys);
        self::assertNull($keys[0]->from);
        self::assertNull($keys[2]->to);
        foreach (\array_slice($keys, 1) as $key) {
            self::assertNotNull($key->from);
        }
        foreach (\array_slice($keys, 0, -1) as $key) {
            self::assertNotNull($key->to);
        }
    }

    public function testAnEmptyCatalogueGivesOneChunkWithBothEndsOpenAndNoItems(): void
    {
        $keys = $this->chunkKeys();

        self::assertCount(1, $keys);
        self::assertNull($keys[0]->from);
        self::assertNull($keys[0]->to);
        self::assertSame([], $this->items($keys[0])->items);
    }

    public function testChunksAreDisjointAndTogetherCoverEveryPublishedProduct(): void
    {
        $this->createProducts(10);

        $slugs = [];
        foreach ($this->chunkKeys() as $key) {
            foreach ($this->items($key)->items as $item) {
                $slugs[] = $item->slug;
            }
        }

        self::assertCount(10, $slugs);
        self::assertSame(array_map(static fn (int $n): string => 'product-'.$n, range(1, 10)), $slugs);
    }

    public function testDraftsAreSkippedButStillCountedWhenTheChunksAreCut(): void
    {
        $this->createProducts(7, [2 => ProductStatus::Draft, 5 => ProductStatus::Draft, 7 => ProductStatus::Draft]);

        self::assertSame([[1, 3], [4, 6], []], $this->numbersByChunk());
    }

    public function testAChunkOfOnlyDraftsHasNoItems(): void
    {
        $this->createProducts(3, [1 => ProductStatus::Draft, 2 => ProductStatus::Draft, 3 => ProductStatus::Draft]);

        self::assertSame([[]], $this->numbersByChunk());
    }

    public function testItemsAreInIdOrderWithTheSlugAndTheTimeOfTheLastChange(): void
    {
        // Created in the reverse order of the ids.
        $this->createProduct(3, new \DateTimeImmutable('2026-03-03 10:00:00 +01:00'));
        $this->createProduct(1, new \DateTimeImmutable('2026-01-01 08:30:00 UTC'));
        $this->createProduct(2, new \DateTimeImmutable('2026-02-02 23:59:59 -05:00'));

        $items = $this->items($this->chunkKeys()[0])->items;

        self::assertSame(['product-1', 'product-2', 'product-3'], array_column($this->itemRows($items), 'slug'));
        // Whatever the zone of the database session, the time is the same instant with an offset.
        self::assertSame(
            ['2026-01-01T08:30:00+00:00', '2026-02-03T04:59:59+00:00', '2026-03-03T09:00:00+00:00'],
            array_column($this->itemRows($items), 'updatedAt'),
        );
    }

    public function testProductsCreatedAfterTheChunksWereCutFallIntoTheFirstAndTheLastChunk(): void
    {
        $this->createProducts(7);
        $keys = $this->chunkKeys();
        self::assertCount(3, $keys);

        // The ids 00… (below the first boundary) and ff… (above the last one) arrive after the boundaries were taken.
        $this->createProduct(0, null, 'below', '00000000-0000-4000-8000-000000000000');
        $this->createProduct(0, null, 'above', 'ffffffff-ffff-4fff-8fff-ffffffffffff');

        $slugsOf = fn (SitemapChunkKey $key): array => array_column($this->itemRows($this->items($key)->items), 'slug');
        self::assertSame(['below', 'product-1', 'product-2', 'product-3'], $slugsOf($keys[0]));
        self::assertSame(['product-4', 'product-5', 'product-6'], $slugsOf($keys[1]));
        self::assertSame(['product-7', 'above'], $slugsOf($keys[2]));
    }

    public function testTheBoundariesAreNotReadAgainWithinTheirTimeToLive(): void
    {
        $this->createProducts(7);
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $this->chunkKeys();

        $before = $counter->count();
        $this->chunkKeys();

        self::assertSame(0, $counter->count() - $before);
    }

    public function testAKeyThatWasNeverIssuedIsRejected(): void
    {
        try {
            $this->queryBus->dispatch(new ListSitemapProducts('not-a-key'));
            self::fail('The key must be rejected.');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(InvalidSitemapChunk::class, array_values($failure->getWrappedExceptions())[0] ?? null);
        }
    }

    /**
     * @return list<SitemapChunkKey>
     */
    private function chunkKeys(): array
    {
        $envelope = $this->queryBus->dispatch(new ListSitemapProductChunks());
        $list = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(SitemapChunkList::class, $list);

        return array_map(static fn (SitemapChunk $chunk): SitemapChunkKey => SitemapChunkKey::decode($chunk->key), $list->chunks);
    }

    private function items(SitemapChunkKey $key): SitemapProductList
    {
        $envelope = $this->queryBus->dispatch(new ListSitemapProducts($key->encode()));
        $list = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(SitemapProductList::class, $list);

        return $list;
    }

    /**
     * @return list<list<int>> the numbers of the products (the slug `product-<n>`) of every chunk in the order of the list
     */
    private function numbersByChunk(): array
    {
        return array_map(
            fn (SitemapChunkKey $key): array => array_map(
                static fn (array $row): int => (int) substr($row['slug'], \strlen('product-')),
                $this->itemRows($this->items($key)->items),
            ),
            $this->chunkKeys(),
        );
    }

    /**
     * @param list<SitemapProduct> $items
     *
     * @return list<array{slug: string, updatedAt: string}>
     */
    private function itemRows(array $items): array
    {
        return array_map(static fn (SitemapProduct $item): array => ['slug' => $item->slug, 'updatedAt' => $item->updatedAt], $items);
    }

    /**
     * @param array<int, ProductStatus> $statuses status by the number of the product, published by default
     */
    private function createProducts(int $count, array $statuses = []): void
    {
        for ($number = 1; $number <= $count; ++$number) {
            $this->createProduct($number, null, null, null, $statuses[$number] ?? ProductStatus::Published);
        }
    }

    private function createProduct(int $number, ?\DateTimeImmutable $updatedAt = null, ?string $slug = null, ?string $id = null, ProductStatus $status = ProductStatus::Published): void
    {
        $product = ProductFactory::createOne([
            'id' => $id ?? self::id($number),
            'slug' => $slug ?? 'product-'.$number,
            'mainCategory' => $this->category,
            'status' => $status,
        ]);
        $this->products->save($product, null, null, $updatedAt ?? new \DateTimeImmutable());
    }

    private static function id(int $number): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
