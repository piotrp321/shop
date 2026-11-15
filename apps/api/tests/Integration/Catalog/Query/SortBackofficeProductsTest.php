<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Query;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalBackofficeProductSource;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The sorts of the product list of the panel on PostgreSQL (shop_test, rolled back after each test): the order of every
 * key with ties on the sorted column, the keyset between pages, and the index that delivers the order of the first page.
 */
final class SortBackofficeProductsTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private RecordingConnection $recording;
    private DbalBackofficeProductSource $source;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->recording = RecordingConnection::like($this->connection);
        $this->source = new DbalBackofficeProductSource($this->recording, new BoundedCounter($this->recording));
    }

    /**
     * @return iterable<string, array{ProductSort}>
     */
    public static function sorts(): iterable
    {
        foreach (ProductSort::cases() as $sort) {
            yield $sort->value => [$sort];
        }
    }

    /**
     * Seven products: three share a name, three share a moment of the last change, so the id decides within the tie.
     *
     * @return list<array{id: string, name: string, updatedAt: string}>
     */
    private function seed(): array
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $products = new DoctrineProductRepository($this->entityManager);
        $rows = [];
        $specs = [
            ['Delta', '2026-11-03 10:00:00'], ['Alfa', '2026-11-01 10:00:00'], ['Charlie', '2026-11-02 10:00:00'], ['Alfa', '2026-11-01 10:00:00'],
            ['Bravo', '2026-11-02 10:00:00'], ['Alfa', '2026-11-03 10:00:00'], ['Echo', '2026-11-02 10:00:00'],
        ];
        foreach ($specs as [$name, $updatedAt]) {
            $product = ProductFactory::createOne(['name' => $name, 'mainCategory' => $category]);
            $products->save($product, null, null, new \DateTimeImmutable());
            $this->connection->executeStatement('UPDATE catalog_product SET updated_at = ? WHERE id = ?', [$updatedAt.'+00', $product->id()->toString()]);
            $rows[] = ['id' => $product->id()->toString(), 'name' => $name, 'updatedAt' => $updatedAt];
        }

        return $rows;
    }

    /**
     * @param list<array{id: string, name: string, updatedAt: string}> $rows
     *
     * @return list<string> the ids in the order of the sort, the id breaking the ties in the direction of the sort
     */
    private static function expected(array $rows, ProductSort $sort): array
    {
        $column = $sort->byUpdatedAt() ? 'updatedAt' : 'name';
        // PostgreSQL compares names by the collation of the database, the ones of this test are plain ASCII.
        usort($rows, static fn (array $a, array $b): int => [$a[$column], $a['id']] <=> [$b[$column], $b['id']]);
        if ($sort->isDescending()) {
            $rows = array_reverse($rows);
        }

        return array_column($rows, 'id');
    }

    #[DataProvider('sorts')]
    public function testEveryKeySortsInItsDirectionAndTheKeysetVisitsEveryProductOnce(ProductSort $sort): void
    {
        $rows = $this->seed();

        $onePage = array_map(static fn (BackofficeProduct $p): string => $p->id, $this->source->page(null, null, null, null, $sort, 100));
        self::assertSame(self::expected($rows, $sort), $onePage);

        $seen = [];
        $after = null;
        $pages = 0;
        do {
            $page = $this->source->page(null, null, null, $after, $sort, 3);
            array_push($seen, ...array_map(static fn (BackofficeProduct $p): string => $p->id, $page));
            $after = [] === $page ? null : ProductCursor::decode(ProductCursor::after($page[array_key_last($page)], $sort)->encode(), $sort);
            ++$pages;
        } while (3 === \count($page) && $pages < 10);

        self::assertSame($onePage, $seen, 'the pages together are the one page, without a gap or a repeat across the ties');
    }

    #[DataProvider('sorts')]
    public function testTheStatusFilterKeepsTheSortAndTheKeyset(ProductSort $sort): void
    {
        $rows = $this->seed();
        $drafts = [$rows[0]['id'], $rows[2]['id'], $rows[3]['id'], $rows[5]['id']];
        $this->connection->executeStatement("UPDATE catalog_product SET status = 'draft' WHERE id IN (?, ?, ?, ?)", $drafts);
        $draftRows = array_values(array_filter($rows, static fn (array $row): bool => \in_array($row['id'], $drafts, true)));

        $first = $this->source->page(null, null, ProductStatus::Draft, null, $sort, 2);
        $second = $this->source->page(null, null, ProductStatus::Draft, ProductCursor::decode(ProductCursor::after($first[1], $sort)->encode(), $sort), $sort, 2);

        self::assertSame(
            self::expected($draftRows, $sort),
            array_map(static fn (BackofficeProduct $p): string => $p->id, [...$first, ...$second]),
        );
    }

    /**
     * @return iterable<string, array{ProductSort, ?ProductStatus, string}>
     */
    public static function indexedFirstPages(): iterable
    {
        yield 'name' => [ProductSort::Name, null, 'catalog_product_name_id_idx'];
        yield 'name descending' => [ProductSort::NameDescending, null, 'catalog_product_name_id_idx'];
        yield 'updated last first' => [ProductSort::UpdatedAtDescending, null, 'catalog_product_updated_at_id_idx'];
        yield 'updated first first' => [ProductSort::UpdatedAtAscending, null, 'catalog_product_updated_at_id_idx'];
        yield 'drafts by name' => [ProductSort::Name, ProductStatus::Draft, 'catalog_product_draft_name_id_idx'];
        yield 'drafts by name descending' => [ProductSort::NameDescending, ProductStatus::Draft, 'catalog_product_draft_name_id_idx'];
        yield 'drafts updated last first' => [ProductSort::UpdatedAtDescending, ProductStatus::Draft, 'catalog_product_draft_updated_at_id_idx'];
        yield 'drafts updated first first' => [ProductSort::UpdatedAtAscending, ProductStatus::Draft, 'catalog_product_draft_updated_at_id_idx'];
    }

    #[DataProvider('indexedFirstPages')]
    public function testTheFirstPageOfEveryKeyIsServedByAnIndexWithoutASort(ProductSort $sort, ?ProductStatus $status, string $index): void
    {
        $this->seed();

        $this->source->page(null, null, $status, null, $sort, 26);
        $plan = $this->recording->planOfTheLastQuery($this->connection);

        self::assertStringContainsString($index, $plan);
        self::assertStringNotContainsString('Sort', $plan);
        self::assertStringNotContainsString('Seq Scan', $plan);
    }
}
