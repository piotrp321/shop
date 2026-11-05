<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Persistence;

use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\VariantId;
use App\Inventory\Infrastructure\Persistence\Doctrine\DoctrineStockItemRepository;
use App\Inventory\Infrastructure\Persistence\Doctrine\Record\StockItemRecord;
use App\Tests\Integration\Inventory\IndependentConnections;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The stock repository on PostgreSQL: shop_test, rolled back after each test, and independent connections that
 * commit where a test needs a second transaction (deleted after the test).
 */
final class DoctrineStockItemRepositoryTest extends KernelTestCase
{
    use IndependentConnections;

    private Connection $connection;
    private DoctrineStockItemRepository $repository;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->repository = new DoctrineStockItemRepository($connection);
    }

    public function testLockManyReturnsTheStoredStocksInAscendingVariantIdWithoutTheMissingOnes(): void
    {
        $ids = [StockFixture::stock($this->connection, 3), StockFixture::stock($this->connection, 5, 1, 4), StockFixture::stock($this->connection, 7)];
        sort($ids);
        $missing = (string) Uuid::v7();

        $items = $this->connection->transactional(fn (): array => $this->repository->lockMany(array_map(VariantId::fromString(...), [$ids[2], $missing, $ids[0], $ids[1]])));

        self::assertSame($ids, array_map(static fn (StockItem $i): string => $i->id()->toString(), $items));
        self::assertSame([5, 1, 4, 4], [$items[1]->physical(), $items[1]->reserved(), $items[1]->available(), $items[1]->version()]);
        self::assertSame([], $this->repository->lockMany([]));
    }

    public function testLockManyHoldsTheRowLocksUntilTheEndOfTheTransaction(): void
    {
        $setup = $this->independentConnection();
        $locked = StockFixture::stock($setup, 5);
        $free = StockFixture::stock($setup, 5);
        $this->committedVariantIds = [$locked, $free];
        $holder = $this->independentConnection();
        $other = $this->independentConnection();

        $holder->beginTransaction();
        new DoctrineStockItemRepository($holder)->lockMany([VariantId::fromString($locked)]);

        self::assertLockNotAvailable(static fn () => $other->fetchOne('SELECT 1 FROM inventory_stock_item WHERE variant_id = ? FOR UPDATE NOWAIT', [$locked]));
        self::assertSame(1, $other->fetchOne('SELECT 1 FROM inventory_stock_item WHERE variant_id = ? FOR UPDATE NOWAIT', [$free]));

        $holder->commit();
        self::assertSame(1, $other->fetchOne('SELECT 1 FROM inventory_stock_item WHERE variant_id = ? FOR UPDATE NOWAIT', [$locked]));
    }

    public function testFindManyReadsTheStoredStocksWithoutLockingThem(): void
    {
        $setup = $this->independentConnection();
        $id = StockFixture::stock($setup, 5, 2);
        $this->committedVariantIds = [$id];
        $reader = $this->independentConnection();
        $other = $this->independentConnection();

        $reader->beginTransaction();
        $items = new DoctrineStockItemRepository($reader)->findMany([VariantId::fromString($id), VariantId::fromString((string) Uuid::v7())]);

        self::assertCount(1, $items);
        self::assertSame([5, 2], [$items[0]->physical(), $items[0]->reserved()]);
        self::assertSame(1, $other->fetchOne('SELECT 1 FROM inventory_stock_item WHERE variant_id = ? FOR UPDATE NOWAIT', [$id]));
    }

    public function testSaveWritesTheStateAtTheNextVersionAndAppendsTheMovements(): void
    {
        $id = StockFixture::stock($this->connection, 10, 1, 3);

        $this->connection->transactional(function () use ($id): void {
            $item = $this->repository->lockMany([VariantId::fromString($id)])[0];
            $item->reserve(4, ReservationKey::fromString('order-1'));
            $item->commit(2, ReservationKey::fromString('order-0'));
            $this->repository->save($item);
        });

        self::assertSame(
            ['physical' => 8, 'reserved' => 3, 'version' => 4],
            $this->connection->fetchAssociative('SELECT physical, reserved, version FROM inventory_stock_item WHERE variant_id = ?', [$id]),
        );
        self::assertSame(
            [
                ['type' => 'reservation', 'physical_delta' => 0, 'reserved_delta' => 4, 'physical_after' => 10, 'reserved_after' => 5, 'reason' => null, 'note' => null, 'reference' => 'order-1', 'source' => 'system', 'actor_id' => null, 'actor_name' => null],
                ['type' => 'commit', 'physical_delta' => -2, 'reserved_delta' => -2, 'physical_after' => 8, 'reserved_after' => 3, 'reason' => null, 'note' => null, 'reference' => 'order-0', 'source' => 'system', 'actor_id' => null, 'actor_name' => null],
            ],
            $this->connection->fetchAllAssociative("SELECT type, physical_delta, reserved_delta, physical_after, reserved_after, reason, note, reference, source, actor_id, actor_name FROM inventory_stock_movement WHERE variant_id = ? AND type <> 'receipt' ORDER BY id", [$id]),
        );
        self::assertSame(2, $this->connection->fetchOne("SELECT COUNT(*) FROM inventory_stock_movement WHERE variant_id = ? AND type <> 'receipt' AND occurred_at IS NOT NULL", [$id]));
    }

    public function testSaveWithoutChangesWritesNothing(): void
    {
        $id = StockFixture::stock($this->connection, 10);
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $item = $this->repository->findMany([VariantId::fromString($id)])[0];

        $before = $counter->count();
        $this->repository->save($item);

        self::assertSame($before, $counter->count());
    }

    public function testSaveOfAStockChangedSinceItWasReadIsRefusedWithoutMovements(): void
    {
        $id = StockFixture::stock($this->connection, 10, 0, 3);
        $item = $this->repository->findMany([VariantId::fromString($id)])[0];
        $this->connection->executeStatement('UPDATE inventory_stock_item SET reserved = 1, version = 4 WHERE variant_id = ?', [$id]);
        $item->reserve(2, ReservationKey::fromString('order-1'));

        try {
            $this->repository->save($item);
            self::fail('Expected a LogicException.');
        } catch (\LogicException) {
        }

        self::assertSame(['reserved' => 1, 'version' => 4], $this->connection->fetchAssociative('SELECT reserved, version FROM inventory_stock_item WHERE variant_id = ?', [$id]));
        self::assertSame(0, $this->connection->fetchOne("SELECT COUNT(*) FROM inventory_stock_movement WHERE variant_id = ? AND type <> 'receipt'", [$id]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statementsBreakingTheInvariant(): iterable
    {
        yield 'negative reserved' => ['UPDATE inventory_stock_item SET reserved = -1 WHERE variant_id = :id'];
        yield 'reserved above physical' => ['UPDATE inventory_stock_item SET reserved = physical + 1 WHERE variant_id = :id'];
        yield 'physical below reserved' => ['UPDATE inventory_stock_item SET physical = 1 WHERE variant_id = :id'];
        yield 'physical above the limit' => ['UPDATE inventory_stock_item SET physical = 1000001 WHERE variant_id = :id'];
        yield 'negative physical' => ['UPDATE inventory_stock_item SET physical = -1, reserved = -1 WHERE variant_id = :id'];
        yield 'insert above the limit' => ['INSERT INTO inventory_stock_item (variant_id, physical, reserved, version, updated_at) VALUES (gen_random_uuid(), 1000001, 0, 1, now())'];
        yield 'insert with reserved above physical' => ['INSERT INTO inventory_stock_item (variant_id, physical, reserved, version, updated_at) VALUES (gen_random_uuid(), 1, 2, 1, now())'];
    }

    #[DataProvider('statementsBreakingTheInvariant')]
    public function testTheDatabaseRefusesAStateBreakingTheInvariant(string $statement): void
    {
        $id = StockFixture::stock($this->connection, 10, 5);

        // A savepoint, so the failed statement leaves the test's transaction usable.
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement($statement, str_contains($statement, ':id') ? ['id' => $id] : []);
            self::fail('Expected the CHECK constraint to refuse the statement.');
        } catch (DriverException $refused) {
            self::assertSame('23514', $refused->getSQLState());
            self::assertStringContainsString(StockItemRecord::QUANTITIES_CHECK, $refused->getMessage());
        } finally {
            $this->connection->rollBack();
        }

        self::assertSame(['physical' => 10, 'reserved' => 5], $this->connection->fetchAssociative('SELECT physical, reserved FROM inventory_stock_item WHERE variant_id = ?', [$id]));
    }

    public function testTheLimitsThemselvesAreAccepted(): void
    {
        $id = StockFixture::stock($this->connection, 1_000_000, 1_000_000);
        $this->connection->executeStatement('UPDATE inventory_stock_item SET physical = 0, reserved = 0 WHERE variant_id = ?', [$id]);

        self::assertSame(['physical' => 0, 'reserved' => 0], $this->connection->fetchAssociative('SELECT physical, reserved FROM inventory_stock_item WHERE variant_id = ?', [$id]));
    }
}
