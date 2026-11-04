<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Persistence;

use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Stock\CorrectionReason;
use App\Inventory\Domain\Stock\ReceiptKey;
use App\Inventory\Domain\Stock\ReceiptKeyReused;
use App\Inventory\Domain\Stock\StockChangeOrigin;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockMovementType;
use App\Inventory\Domain\Stock\StockNote;
use App\Inventory\Domain\Stock\VariantId;
use App\Inventory\Infrastructure\Persistence\Doctrine\DoctrineStockItemRepository;
use App\Tests\Integration\Inventory\IndependentConnections;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Creating a stock for a receipt or a correction, and finding receipts by their key, on PostgreSQL: shop_test, rolled
 * back after each test, and independent connections that commit where a test needs a second transaction (deleted
 * after the test).
 */
final class DoctrineStockItemRepositoryReceiptsTest extends KernelTestCase
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

    public function testLockOrCreateOfAVariantWithoutStockCreatesItWithZerosAtVersionZero(): void
    {
        $id = (string) Uuid::v7();

        $item = $this->connection->transactional(fn (): StockItem => $this->repository->lockOrCreate(VariantId::fromString($id)));

        self::assertSame([$id, 0, 0, 0], [$item->id()->toString(), $item->physical(), $item->reserved(), $item->version()]);
        self::assertSame(
            ['physical' => 0, 'reserved' => 0, 'version' => 0],
            $this->connection->fetchAssociative('SELECT physical, reserved, version FROM inventory_stock_item WHERE variant_id = ?', [$id]),
        );
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_stock_movement WHERE variant_id = ?', [$id]));
    }

    public function testLockOrCreateOfAStoredStockReturnsItAsStored(): void
    {
        $id = StockFixture::stock($this->connection, 7, 2, 5);

        $item = $this->connection->transactional(fn (): StockItem => $this->repository->lockOrCreate(VariantId::fromString($id)));

        self::assertSame([7, 2, 5], [$item->physical(), $item->reserved(), $item->version()]);
        self::assertSame(
            ['physical' => 7, 'reserved' => 2, 'version' => 5],
            $this->connection->fetchAssociative('SELECT physical, reserved, version FROM inventory_stock_item WHERE variant_id = ?', [$id]),
        );
    }

    public function testLockOrCreateHoldsTheRowLockUntilTheEndOfTheTransaction(): void
    {
        $id = StockFixture::stock($this->independentConnection(), 5);
        $this->committedVariantIds = [$id];
        $holder = $this->independentConnection();
        $other = $this->independentConnection();

        $holder->beginTransaction();
        new DoctrineStockItemRepository($holder)->lockOrCreate(VariantId::fromString($id));

        self::assertLockNotAvailable(static fn () => $other->fetchOne('SELECT 1 FROM inventory_stock_item WHERE variant_id = ? FOR UPDATE NOWAIT', [$id]));
        $holder->commit();
        self::assertSame(1, $other->fetchOne('SELECT 1 FROM inventory_stock_item WHERE variant_id = ? FOR UPDATE NOWAIT', [$id]));
    }

    public function testACreationWaitsForAConcurrentUncommittedCreationOfTheSameStock(): void
    {
        $id = (string) Uuid::v7();
        $this->committedVariantIds = [$id];
        $first = $this->independentConnection();
        $second = $this->independentConnection();
        $second->executeStatement("SET lock_timeout = '200ms'");

        $first->beginTransaction();
        new DoctrineStockItemRepository($first)->lockOrCreate(VariantId::fromString($id));

        $second->beginTransaction();
        self::assertLockNotAvailable(static fn () => new DoctrineStockItemRepository($second)->lockOrCreate(VariantId::fromString($id)));
        $second->rollBack();
        $first->commit();
    }

    public function testACreatedStockIsSavedAtVersionOneWithItsReceipt(): void
    {
        $id = (string) Uuid::v7();

        $this->connection->transactional(function () use ($id): void {
            $item = $this->repository->lockOrCreate(VariantId::fromString($id));
            $item->receive(12, ReceiptKey::fromString('delivery-1'), StockNote::fromNullable('Dostawa'), StockChangeOrigin::staff('sub-1', 'Wanda Magazyn'));
            $this->repository->save($item);
        });

        self::assertSame(
            ['physical' => 12, 'reserved' => 0, 'version' => 1],
            $this->connection->fetchAssociative('SELECT physical, reserved, version FROM inventory_stock_item WHERE variant_id = ?', [$id]),
        );
        self::assertSame(
            [['type' => 'receipt', 'physical_delta' => 12, 'physical_after' => 12, 'reason' => null, 'note' => 'Dostawa', 'reference' => 'delivery-1', 'source' => 'staff', 'actor_id' => 'sub-1', 'actor_name' => 'Wanda Magazyn']],
            $this->connection->fetchAllAssociative('SELECT type, physical_delta, physical_after, reason, note, reference, source, actor_id, actor_name FROM inventory_stock_movement WHERE variant_id = ?', [$id]),
        );
    }

    public function testACorrectionIsSavedWithItsReason(): void
    {
        $id = StockFixture::stock($this->connection, 10, 0, 2);

        $this->connection->transactional(function () use ($id): void {
            $item = $this->repository->lockOrCreate(VariantId::fromString($id));
            $item->correct(7, CorrectionReason::Damage, StockNote::none(), StockChangeOrigin::staff('sub-1', 'Wanda Magazyn'));
            $this->repository->save($item);
        });

        self::assertSame(
            [['type' => 'correction', 'physical_delta' => -3, 'physical_after' => 7, 'reason' => 'damage', 'note' => null, 'reference' => null]],
            $this->connection->fetchAllAssociative("SELECT type, physical_delta, physical_after, reason, note, reference FROM inventory_stock_movement WHERE variant_id = ? AND type = 'correction'", [$id]),
        );
    }

    public function testFindReceiptReturnsTheReceiptOfTheKey(): void
    {
        $id = (string) Uuid::v7();
        $this->receive($id, 'delivery-1', 5, 'Dostawa', StockChangeOrigin::staff('sub-1', 'Wanda Magazyn'));
        $this->receive($id, 'delivery-2', 3, null, StockChangeOrigin::seed());

        $receipt = $this->repository->findReceipt(ReceiptKey::fromString('delivery-1'));

        self::assertNotNull($receipt);
        self::assertSame(StockMovementType::Receipt, $receipt->type());
        self::assertSame($id, $receipt->variantId()->toString());
        self::assertSame([5, 0, 5, 0], [$receipt->physicalDelta(), $receipt->reservedDelta(), $receipt->physicalAfter(), $receipt->reservedAfter()]);
        self::assertSame(['Dostawa', 'delivery-1', null], [$receipt->note(), $receipt->reference(), $receipt->reason()]);
        self::assertSame([StockChangeSource::Staff, 'sub-1', 'Wanda Magazyn'], [$receipt->origin()->source(), $receipt->origin()->actorId(), $receipt->origin()->actorName()]);
        self::assertTrue($receipt->isReceiptOf(VariantId::fromString($id), 5, StockNote::fromNullable('Dostawa')));

        $seeded = $this->repository->findReceipt(ReceiptKey::fromString('delivery-2'));
        self::assertNotNull($seeded);
        self::assertSame([StockChangeSource::Seed, null, null], [$seeded->origin()->source(), $seeded->origin()->actorId(), $seeded->note()]);
    }

    public function testFindReceiptOfAnUnknownKeyIsNull(): void
    {
        self::assertNull($this->repository->findReceipt(ReceiptKey::fromString('never-used')));
    }

    public function testFindReceiptIgnoresAReservationWithTheSameReference(): void
    {
        $id = StockFixture::stock($this->connection, 10);
        $this->connection->transactional(function () use ($id): void {
            $item = $this->repository->lockMany([VariantId::fromString($id)])[0];
            $item->reserve(1, ReservationKey::fromString('shared-1'));
            $this->repository->save($item);
        });

        self::assertNull($this->repository->findReceipt(ReceiptKey::fromString('shared-1')));
    }

    public function testFindReceiptMatchesTheKeyExactly(): void
    {
        $this->receive((string) Uuid::v7(), 'Delivery-1', 5, null, StockChangeOrigin::seed());

        self::assertNull($this->repository->findReceipt(ReceiptKey::fromString('delivery-1')));
    }

    public function testSavingAReceiptWithAKeyOfAnotherVariantsReceiptIsReceiptKeyReused(): void
    {
        $first = (string) Uuid::v7();
        $second = StockFixture::stock($this->connection, 4, 0, 2);
        $this->receive($first, 'delivery-1', 5, null, StockChangeOrigin::seed());

        try {
            // As if the history of the key had been read before the other receipt committed: only the unique index
            // of receipt keys refuses the second one.
            $this->receive($second, 'delivery-1', 5, null, StockChangeOrigin::seed());
            self::fail('Expected ReceiptKeyReused.');
        } catch (ReceiptKeyReused $reused) {
            self::assertSame('Klucz przyjęcia został już użyty z inną treścią albo dla innego produktu.', $reused->getMessage());
        }

        self::assertSame(
            ['physical' => 4, 'version' => 2],
            $this->connection->fetchAssociative('SELECT physical, version FROM inventory_stock_item WHERE variant_id = ?', [$second]),
        );
        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM inventory_stock_movement WHERE reference = 'delivery-1'"));
    }

    /**
     * One receipt in a transaction of its own (a savepoint of the test's transaction).
     */
    private function receive(string $variantId, string $key, int $quantity, ?string $note, StockChangeOrigin $origin): void
    {
        $this->connection->transactional(function () use ($variantId, $key, $quantity, $note, $origin): void {
            $item = $this->repository->lockOrCreate(VariantId::fromString($variantId));
            $item->receive($quantity, ReceiptKey::fromString($key), StockNote::fromNullable($note), $origin);
            $this->repository->save($item);
        });
    }
}
