<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * inventory:stock-records:sync on PostgreSQL (shop_test, rolled back after each test), on data from before the stock
 * record of every variant: variants without a record and records without the product.
 */
final class SyncStockRecordsCommandTest extends KernelTestCase
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

    public function testEveryVariantGetsARecordWithItsProductAndTheStockStaysAsItWas(): void
    {
        [$tent, [$tentVariant]] = $this->product([]);
        [$kayak, [$green, $orange, $yellow]] = $this->product(['zielony', 'pomarańczowy', 'żółty']);
        [$draft, [$draftVariant]] = $this->product([], 'draft');
        // Older data: a receipt and an empty record from before the column, and variants without a record.
        StockFixture::stock($this->connection, 12, 2, 3, $green);
        $this->connection->executeStatement('INSERT INTO inventory_stock_item (variant_id, physical, reserved, version, updated_at) VALUES (?, 0, 0, 0, now())', [$orange]);
        $movements = $this->connection->fetchOne('SELECT count(*) FROM inventory_stock_movement');

        $tester = $this->sync();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('records written: 5', $tester->getDisplay());
        self::assertSame(0, $this->variantsWithoutARecord());
        self::assertSame(0, $this->recordsOfVariantsWithoutTheProduct());
        self::assertSame(
            self::sortedByVariant([
                [$tentVariant, $tent, 0, 0, 0],
                [$green, $kayak, 12, 2, 3],
                [$orange, $kayak, 0, 0, 0],
                [$yellow, $kayak, 0, 0, 0],
                [$draftVariant, $draft, 0, 0, 0],
            ]),
            $this->records([$tentVariant, $green, $orange, $yellow, $draftVariant]),
        );
        self::assertSame($movements, $this->connection->fetchOne('SELECT count(*) FROM inventory_stock_movement'));
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->product(['zielony', 'pomarańczowy']);
        [, [$tentVariant]] = $this->product([]);
        StockFixture::stock($this->connection, 5, 0, 1, $tentVariant);
        $this->sync();
        $before = $this->connection->fetchAllAssociative('SELECT * FROM inventory_stock_item ORDER BY variant_id');

        $tester = $this->sync();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('records written: 0', $tester->getDisplay());
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM inventory_stock_item ORDER BY variant_id'));
    }

    private function sync(): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find('inventory:stock-records:sync'));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @param list<string> $colours values of a colour axis; none for one default variant
     *
     * @return array{string, list<string>} the product id and the ids of its variants, in the order of the colours
     */
    private function product(array $colours, string $status = 'published'): array
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $attributes = ['mainCategory' => $category];
        if ([] !== $colours) {
            $attributes += ['variantAxes' => ['Kolor'], 'variants' => array_map(static fn (string $colour): array => ['id' => (string) Uuid::v7(), 'values' => [$colour]], $colours)];
        }
        $product = ProductFactory::createOne($attributes);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();
        $productId = $product->id()->toString();
        $this->connection->executeStatement('UPDATE catalog_product SET status = ? WHERE id = ?', [$status, $productId]);

        return [$productId, array_map(static fn (ProductVariant $variant): string => $variant->id()->toString(), $product->variants()->toList())];
    }

    private function variantsWithoutARecord(): mixed
    {
        return $this->connection->fetchOne('SELECT count(*) FROM catalog_product_variant v WHERE NOT EXISTS (SELECT 1 FROM inventory_stock_item s WHERE s.variant_id = v.id)');
    }

    private function recordsOfVariantsWithoutTheProduct(): mixed
    {
        return $this->connection->fetchOne('SELECT count(*) FROM inventory_stock_item s JOIN catalog_product_variant v ON v.id = s.variant_id WHERE s.product_id IS DISTINCT FROM v.product_id');
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
     * @param list<array{string, string, int, int, int}> $records
     *
     * @return list<array{string, string, int, int, int}>
     */
    private static function sortedByVariant(array $records): array
    {
        usort($records, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $records;
    }
}
