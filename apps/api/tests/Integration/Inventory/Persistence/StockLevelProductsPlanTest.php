<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Persistence;

use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Infrastructure\Persistence\Doctrine\DbalStockLevelProducts;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The products of a stock level on 100 000 records, with the plan as the planner chooses it on its own statistics (after
 * ANALYZE), not steered by switches: a page and the count are read through the partial index of the level and the table
 * is not scanned whole.
 *
 * The records are in a temporary table that takes the place of inventory_stock_item for the connection of the test (a
 * copy of its definition with its indexes), so the table of the test database is not filled with rows that the rollback
 * leaves behind as garbage.
 */
final class StockLevelProductsPlanTest extends KernelTestCase
{
    private const int RECORDS = 100_000;
    /** Every 1 000th record is out of stock (100 products), every 997th is low (about 100 more). */
    private const int OUT_EVERY = 1_000;
    private const int LOW_EVERY = 997;

    private Connection $connection;
    private RecordingConnection $recording;
    private DbalStockLevelProducts $levels;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('CREATE TEMP TABLE inventory_stock_item (LIKE public.inventory_stock_item INCLUDING ALL)');
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO inventory_stock_item (variant_id, product_id, physical, reserved, version, updated_at)
                SELECT gen_random_uuid(), md5('p'||(n / 2))::uuid,
                       CASE WHEN n % :out = 0 THEN 4 WHEN n % :low = 0 THEN 9 ELSE 100 END,
                       CASE WHEN n % :out = 0 THEN 4 WHEN n % :low = 0 THEN 6 ELSE 0 END,
                       1, now()
                FROM generate_series(1, :records) AS n
                SQL,
            ['out' => self::OUT_EVERY, 'low' => self::LOW_EVERY, 'records' => self::RECORDS],
        );
        // Records without their product are in the partial indexes too, behind every product.
        $this->connection->executeStatement('INSERT INTO inventory_stock_item (variant_id, product_id, physical, reserved, version, updated_at) VALUES (gen_random_uuid(), NULL, 0, 0, 1, now())');
        $this->connection->executeStatement('ANALYZE inventory_stock_item');
        $this->recording = RecordingConnection::like($this->connection);
        $this->levels = new DbalStockLevelProducts($this->recording);
    }

    public function testAPageAndTheCountOfEveryLevelAreReadThroughItsPartialIndexNotByAScanOfTheTable(): void
    {
        foreach ([AvailabilityLevel::OutOfStock, AvailabilityLevel::LowStock] as $level) {
            $index = $this->indexOf($level);
            $first = $this->levels->page($level, null, 25);
            $firstPlan = $this->recording->planOfTheLastQuery($this->connection, false);
            $second = $this->levels->page($level, $first[24], 25);
            $secondPlan = $this->recording->planOfTheLastQuery($this->connection, false);
            $count = $this->levels->count($level);
            $countPlan = $this->recording->planOfTheLastQuery($this->connection, false);

            self::assertCount(25, $first, $level->value);
            self::assertCount(25, $second, $level->value);
            self::assertGreaterThan($first[24]->toString(), $second[0]->toString());
            self::assertSame($this->expectedProducts($level), \count($this->all($level)), $level->value);
            self::assertSame([$this->expectedProducts($level), false], [$count->value, $count->capped], $level->value);
            foreach (['first page' => $firstPlan, 'next page' => $secondPlan, 'count' => $countPlan] as $label => $plan) {
                self::assertStringContainsString($index, $plan, $level->value.' '.$label."\n".$plan);
                self::assertDoesNotMatchRegularExpression('/Seq Scan on inventory_stock_item/', $plan, $level->value.' '.$label."\n".$plan);
            }
        }
    }

    public function testTheVariantsOfEveryLevelAreCountedThroughItsPartialIndexUpToTheLimit(): void
    {
        foreach ([AvailabilityLevel::OutOfStock, AvailabilityLevel::LowStock] as $level) {
            $index = $this->indexOf($level);
            $expected = $this->connection->fetchOne(
                (AvailabilityLevel::OutOfStock === $level ? 'SELECT count(*) FROM inventory_stock_item WHERE physical - reserved = 0 AND product_id IS NOT NULL'
                    : 'SELECT count(*) FROM inventory_stock_item WHERE physical - reserved BETWEEN 1 AND 5 AND product_id IS NOT NULL'),
            );

            $all = $this->levels->countVariants($level, 10_000);
            $allPlan = $this->recording->planOfTheLastQuery($this->connection, false);
            $capped = $this->levels->countVariants($level, 20);
            $cappedPlan = $this->recording->planOfTheLastQuery($this->connection, false);

            self::assertSame([$expected, false], [$all->value, $all->capped], $level->value);
            self::assertSame([20, true], [$capped->value, $capped->capped], $level->value);
            foreach (['all' => $allPlan, 'capped' => $cappedPlan] as $label => $plan) {
                self::assertStringContainsString($index, $plan, $level->value.' '.$label."\n".$plan);
                self::assertDoesNotMatchRegularExpression('/Seq Scan on inventory_stock_item/', $plan, $level->value.' '.$label."\n".$plan);
            }
        }
    }

    /**
     * @return list<ProductId> every product of the level, paged by 40
     */
    private function all(AvailabilityLevel $level): array
    {
        $all = [];
        $after = null;
        do {
            $page = $this->levels->page($level, $after, 40);
            $all = [...$all, ...$page];
            $after = [] === $page ? null : $page[array_key_last($page)];
        } while (null !== $after);

        return $all;
    }

    private function expectedProducts(AvailabilityLevel $level): int
    {
        $condition = AvailabilityLevel::OutOfStock === $level ? 'physical - reserved = 0' : 'physical - reserved BETWEEN 1 AND 5';
        $count = $this->connection->fetchOne("SELECT count(DISTINCT product_id) FROM inventory_stock_item WHERE {$condition}");
        self::assertIsInt($count);

        return $count;
    }

    /**
     * The name of the index of the temporary copy by its predicate.
     */
    private function indexOf(AvailabilityLevel $level): string
    {
        $predicate = AvailabilityLevel::OutOfStock === $level ? '%(physical - reserved) = 0%' : '%(physical - reserved) >= 1%';
        $name = $this->connection->fetchOne(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'inventory_stock_item' AND schemaname LIKE 'pg_temp%' AND indexdef LIKE ?",
            [$predicate],
        );
        self::assertIsString($name);

        return $name;
    }
}
