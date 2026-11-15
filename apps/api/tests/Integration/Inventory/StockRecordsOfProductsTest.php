<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Application\Event\ProductCreated;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Application\Command\ReceiveStock;
use App\Inventory\Infrastructure\Catalog\SyncStockRecordsOnProductChange;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The consumer of the Catalog product events on PostgreSQL, through the real query bus, command bus and Catalog
 * (shop_test, rolled back after each test): every variant of the product has a stock record with the product, and a
 * record of a variant the product no longer has goes when it holds nothing.
 */
final class StockRecordsOfProductsTest extends KernelTestCase
{
    use Factories;

    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    public function testACreatedProductGetsAnEmptyRecordWithTheProductForEveryVariant(): void
    {
        [$productId, $variants] = $this->product(['zielony', 'pomarańczowy', 'żółty']);

        $this->consume(new ProductCreated($productId, new \DateTimeImmutable()));

        self::assertSame(
            array_map(static fn (string $variant): array => [$variant, $productId, 0, 0, 0], self::sorted($variants)),
            $this->records($variants),
        );
    }

    public function testAReceiptBeforeTheEventKeepsItsStockAndItsHistoryAndGetsTheProduct(): void
    {
        [$productId, [$green, $orange]] = $this->product(['zielony', 'pomarańczowy']);
        $this->commandBus()->dispatch(ReceiveStock::bySeed($green, 7, 'delivery-'.Uuid::v7()->toRfc4122()));
        self::assertSame([[$green, null, 7, 0, 1]], $this->records([$green]));

        $this->consume(new ProductCreated($productId, new \DateTimeImmutable()));

        self::assertSame(
            array_map(static fn (string $variant): array => [$variant, $productId, $variant === $green ? 7 : 0, 0, $variant === $green ? 1 : 0], self::sorted([$green, $orange])),
            $this->records([$green, $orange]),
        );
        self::assertSame(1, $this->movements([$green]));
    }

    public function testAnEditThatAddsAVariantGivesItARecord(): void
    {
        [$productId, $variants] = $this->product(['zielony']);
        $this->consume(new ProductCreated($productId, new \DateTimeImmutable()));
        $added = (string) Uuid::v7();
        $this->connection->executeStatement("INSERT INTO catalog_product_variant (id, product_id, position, option_values) VALUES (?, ?, 1, '[\"czarny\"]')", [$added, $productId]);

        $this->consume(new ProductChanged($productId, 'kajak', new \DateTimeImmutable()));

        self::assertSame(
            array_map(static fn (string $variant): array => [$variant, $productId, 0, 0, 0], self::sorted([...$variants, $added])),
            $this->records([...$variants, $added]),
        );
    }

    public function testARemovedVariantLosesAnEmptyRecordAndKeepsARecordWithStockAndEveryMovementStays(): void
    {
        [$productId, [$kept, $empty, $stocked]] = $this->product(['zielony', 'pomarańczowy', 'żółty']);
        $this->consume(new ProductCreated($productId, new \DateTimeImmutable()));
        // The empty one had stock once (a receipt, then a correction to 0); the stocked one got a receipt in the same
        // moment as the edit that removed it (Catalog refuses to remove a variant it knows has stock).
        $this->connection->executeStatement('UPDATE inventory_stock_item SET version = 2 WHERE variant_id = ?', [$empty]);
        $this->movement($empty, 3);
        $this->movement($empty, -3);
        $this->connection->executeStatement('UPDATE inventory_stock_item SET physical = 4, version = 1 WHERE variant_id = ?', [$stocked]);
        $this->movement($stocked, 4);
        $this->connection->executeStatement('DELETE FROM catalog_product_variant WHERE id IN (?, ?)', [$empty, $stocked]);

        $this->consume(new ProductChanged($productId, 'kajak', new \DateTimeImmutable()));

        self::assertSame([[$kept, $productId, 0, 0, 0], [$stocked, $productId, 4, 0, 1]], $this->recordsSortedLike([$kept, $stocked]));
        self::assertSame([], $this->records([$empty]));
        self::assertSame(2, $this->movements([$empty]));
        self::assertSame(1, $this->movements([$stocked]));
    }

    public function testARecordWithReservedStockOfARemovedVariantStays(): void
    {
        [$productId, [$kept, $reserved]] = $this->product(['zielony', 'pomarańczowy']);
        $this->consume(new ProductCreated($productId, new \DateTimeImmutable()));
        // physical = reserved: nothing available, but the record holds a reservation.
        $this->connection->executeStatement('UPDATE inventory_stock_item SET physical = 2, reserved = 2, version = 2 WHERE variant_id = ?', [$reserved]);
        $this->connection->executeStatement('DELETE FROM catalog_product_variant WHERE id = ?', [$reserved]);

        $this->consume(new ProductChanged($productId, 'kajak', new \DateTimeImmutable()));

        self::assertSame([[$reserved, $productId, 2, 2, 2]], $this->records([$reserved]));
        self::assertSame([[$kept, $productId, 0, 0, 0]], $this->records([$kept]));
    }

    public function testARedeliveredEventChangesNothing(): void
    {
        [$productId, $variants] = $this->product(['zielony', 'pomarańczowy']);
        $event = new ProductCreated($productId, new \DateTimeImmutable());
        $this->consume($event);
        $before = $this->rows($variants);

        $this->consume($event);
        $this->consume(new ProductChanged($productId, 'kajak', new \DateTimeImmutable()));

        self::assertSame($before, $this->rows($variants));
    }

    public function testAProductCatalogDoesNotHaveChangesNothing(): void
    {
        $before = $this->connection->fetchOne('SELECT count(*) FROM inventory_stock_item');

        $this->consume(new ProductCreated((string) Uuid::v7(), new \DateTimeImmutable()));

        self::assertSame($before, $this->connection->fetchOne('SELECT count(*) FROM inventory_stock_item'));
    }

    public function testAnEventThatDoesNotNameAProductIsNotRetried(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->consume(new ProductChanged('not-a-uuid', 'kajak', new \DateTimeImmutable()));
    }

    private function consume(ProductCreated|ProductChanged $event): void
    {
        $consumer = self::getContainer()->get(SyncStockRecordsOnProductChange::class);
        self::assertInstanceOf(SyncStockRecordsOnProductChange::class, $consumer);
        $consumer($event);
    }

    /**
     * @param list<string> $colours
     *
     * @return array{string, list<string>} the product id and the ids of its variants, in the order of the colours
     */
    private function product(array $colours): array
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $product = ProductFactory::createOne([
            'mainCategory' => $category,
            'variantAxes' => ['Kolor'],
            'variants' => array_map(static fn (string $colour): array => ['id' => (string) Uuid::v7(), 'values' => [$colour]], $colours),
        ]);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        return [$product->id()->toString(), array_map(static fn (ProductVariant $variant): string => $variant->id()->toString(), $product->variants()->toList())];
    }

    private function movement(string $variantId, int $delta): void
    {
        $this->connection->executeStatement(
            "INSERT INTO inventory_stock_movement (id, variant_id, type, physical_delta, reserved_delta, physical_after, reserved_after, reference, source, occurred_at) VALUES (?, ?, 'correction', ?, 0, 0, 0, NULL, 'staff', now())",
            [(string) Uuid::v7(), $variantId, $delta],
        );
    }

    /**
     * @param list<string> $variantIds
     *
     * @return list<list<mixed>> variant, product, physical, reserved and version, by variant id
     */
    private function records(array $variantIds): array
    {
        return array_map(
            static fn (array $row): array => [$row['variant_id'], $row['product_id'], $row['physical'], $row['reserved'], $row['version']],
            $this->connection->fetchAllAssociative(
                'SELECT variant_id, product_id, physical, reserved, version FROM inventory_stock_item WHERE variant_id = ANY(CAST(? AS uuid[])) ORDER BY variant_id',
                ['{'.implode(',', $variantIds).'}'],
            ),
        );
    }

    /**
     * @param list<string> $variantIds
     *
     * @return list<list<mixed>> as records(), in the order of $variantIds
     */
    private function recordsSortedLike(array $variantIds): array
    {
        $records = $this->records($variantIds);
        usort($records, static fn (array $a, array $b): int => array_search($a[0], $variantIds, true) <=> array_search($b[0], $variantIds, true));

        return $records;
    }

    /**
     * @param list<string> $variantIds
     *
     * @return list<array<string, mixed>> every column of the records
     */
    private function rows(array $variantIds): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM inventory_stock_item WHERE variant_id = ANY(CAST(? AS uuid[])) ORDER BY variant_id',
            ['{'.implode(',', $variantIds).'}'],
        );
    }

    /**
     * @param list<string> $variantIds
     */
    private function movements(array $variantIds): mixed
    {
        return $this->connection->fetchOne(
            'SELECT count(*) FROM inventory_stock_movement WHERE variant_id = ANY(CAST(? AS uuid[]))',
            ['{'.implode(',', $variantIds).'}'],
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function commandBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}
