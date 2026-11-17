<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Persistence;

use App\Inventory\Domain\Stock\AvailabilityLevel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The migrated test database and the Doctrine mapping describe the same Inventory schema, and the partial indexes of
 * the stock levels follow the rule of AvailabilityLevel: a change of LOW_STOCK_THRESHOLD alone fails here, it needs a
 * migration of the index too.
 */
final class InventorySchemaTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testMigratedSchemaMatchesTheMapping(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $tool = new SchemaTool($entityManager);

        $updates = array_values(array_filter(
            $tool->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata()),
            static fn (string $sql): bool => str_contains($sql, 'inventory_'),
        ));

        self::assertSame([], $updates);
    }

    public function testTheStockRecordHasANullableProductId(): void
    {
        self::assertSame(
            ['data_type' => 'uuid', 'is_nullable' => 'YES'],
            $this->connection->fetchAssociative(
                "SELECT data_type, is_nullable FROM information_schema.columns WHERE table_name = 'inventory_stock_item' AND column_name = 'product_id'",
            ),
        );
    }

    public function testTheOutOfStockIndexHoldsTheRecordsWithNothingAvailable(): void
    {
        // The predicate is the level: nothing available is out of stock, one item is not.
        self::assertSame(AvailabilityLevel::OutOfStock, AvailabilityLevel::of(0));
        self::assertNotSame(AvailabilityLevel::OutOfStock, AvailabilityLevel::of(1));
        self::assertSame(
            'CREATE INDEX inventory_stock_item_out_of_stock_idx ON public.inventory_stock_item USING btree (product_id) WHERE ((physical - reserved) = 0)',
            $this->indexDefinition('inventory_stock_item_out_of_stock_idx'),
        );
    }

    public function testTheLowStockIndexHoldsTheRecordsOfTheLowStockLevel(): void
    {
        $threshold = AvailabilityLevel::LOW_STOCK_THRESHOLD;
        // The bounds of the predicate are the bounds of the level.
        self::assertSame(AvailabilityLevel::LowStock, AvailabilityLevel::of(1));
        self::assertSame(AvailabilityLevel::LowStock, AvailabilityLevel::of($threshold));
        self::assertSame(AvailabilityLevel::InStock, AvailabilityLevel::of($threshold + 1));
        self::assertSame(
            \sprintf(
                'CREATE INDEX inventory_stock_item_low_stock_idx ON public.inventory_stock_item USING btree (product_id) WHERE (((physical - reserved) >= 1) AND ((physical - reserved) <= %d))',
                $threshold,
            ),
            $this->indexDefinition('inventory_stock_item_low_stock_idx'),
        );
    }

    public function testBothIndexesAreValid(): void
    {
        self::assertSame(
            [['relname' => 'inventory_stock_item_low_stock_idx', 'indisvalid' => true], ['relname' => 'inventory_stock_item_out_of_stock_idx', 'indisvalid' => true]],
            $this->connection->fetchAllAssociative(
                "SELECT c.relname, i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname IN ('inventory_stock_item_out_of_stock_idx', 'inventory_stock_item_low_stock_idx') ORDER BY c.relname",
            ),
        );
    }

    private function indexDefinition(string $name): mixed
    {
        return $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'inventory_stock_item' AND indexname = ?", [$name]);
    }
}
