<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Application\Command\CommitReservation;
use App\Inventory\Application\Command\InsufficientStock;
use App\Inventory\Application\Command\ReleaseReservation;
use App\Inventory\Application\Command\ReservationLineView;
use App\Inventory\Application\Command\ReservationNotFound;
use App\Inventory\Application\Command\ReservationStateConflict;
use App\Inventory\Application\Command\ReservationView;
use App\Inventory\Application\Command\ReserveStock;
use App\Inventory\Application\Command\ReserveStockLine;
use App\Inventory\Application\Command\Shortage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * The reservation port on PostgreSQL, through the real command bus and its doctrine_transaction middleware
 * (shop_test, rolled back after each test; the bus's transaction is a savepoint inside the test's).
 */
final class ReservationCommandsTest extends KernelTestCase
{
    private MessageBusInterface $commandBus;
    private Connection $connection;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->commandBus = $bus;
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testReservationStoresTheReservationAndChangesTheStocksWithTheirMovements(): void
    {
        $a = StockFixture::stock($this->connection, 10);
        $b = StockFixture::stock($this->connection, 3, 1, 5);

        $view = $this->dispatch(new ReserveStock('order-1', [new ReserveStockLine($b, 2), new ReserveStockLine($a, 4)]));

        self::assertEquals(new ReservationView('order-1', 'active', self::sortedLines([$a => 4, $b => 2])), $view);
        self::assertSame(['physical' => 10, 'reserved' => 4, 'version' => 2], $this->stockRow($a));
        self::assertSame(['physical' => 3, 'reserved' => 3, 'version' => 6], $this->stockRow($b));
        self::assertSame([['reservation', 0, 4, 10, 4, 'order-1', 'system']], $this->movements($a));
        self::assertSame([['reservation', 0, 2, 3, 3, 'order-1', 'system']], $this->movements($b));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', ['order-1']));
        self::assertEquals(
            [[$a, 4], [$b, 2]],
            array_map(static fn (array $r): array => [$r['variant_id'], $r['quantity']], $this->connection->fetchAllAssociative('SELECT variant_id, quantity FROM inventory_reservation_line WHERE reservation_key = ? ORDER BY variant_id', ['order-1'])),
        );
    }

    public function testShortageRollsBackTheWholeTransactionAndTheKeyCanBeUsedLater(): void
    {
        $enough = StockFixture::stock($this->connection, 10);
        $short = StockFixture::stock($this->connection, 3, 2);
        $withoutStock = (string) Uuid::v7();
        $lines = [new ReserveStockLine($enough, 5), new ReserveStockLine($withoutStock, 1), new ReserveStockLine($short, 2)];

        try {
            $this->dispatch(new ReserveStock('order-1', $lines));
            self::fail('Expected InsufficientStock.');
        } catch (InsufficientStock $insufficient) {
            $expected = [new Shortage($short, 2, 1), new Shortage($withoutStock, 1, 0)];
            usort($expected, static fn (Shortage $x, Shortage $y): int => strcmp($x->variantId, $y->variantId));
            self::assertEquals($expected, $insufficient->shortages);
        }

        $this->assertNothingReserved('order-1', [$enough => [10, 0, 1], $short => [3, 2, 1]]);

        $this->connection->executeStatement('UPDATE inventory_stock_item SET physical = 4 WHERE variant_id = ?', [$short]);
        StockFixture::stock($this->connection, 1, variantId: $withoutStock);
        self::assertSame('active', $this->dispatch(new ReserveStock('order-1', $lines))->status);
    }

    public function testAFailedHistoryWriteRollsBackTheReservation(): void
    {
        $a = StockFixture::stock($this->connection, 10);
        $b = StockFixture::stock($this->connection, 10);
        $this->refuseHistoryWrites();

        try {
            $this->dispatch(new ReserveStock('order-1', [new ReserveStockLine($a, 1), new ReserveStockLine($b, 1)]));
            self::fail('Expected the history write to fail.');
        } catch (DriverException $failure) {
            self::assertStringContainsString('history write refused by the test', $failure->getMessage());
        }

        $this->assertNothingReserved('order-1', [$a => [10, 0, 1], $b => [10, 0, 1]]);
    }

    public function testAFailedHistoryWriteRollsBackAReleaseAndACommit(): void
    {
        $a = StockFixture::stock($this->connection, 10);
        $this->dispatch(new ReserveStock('order-1', [new ReserveStockLine($a, 4)]));
        $this->refuseHistoryWrites();

        foreach ([new ReleaseReservation('order-1'), new CommitReservation('order-1')] as $transition) {
            try {
                $this->dispatch($transition);
                self::fail('Expected the history write to fail.');
            } catch (DriverException $failure) {
                self::assertStringContainsString('history write refused by the test', $failure->getMessage());
            }

            self::assertSame(['physical' => 10, 'reserved' => 4, 'version' => 2], $this->stockRow($a));
            self::assertSame('active', $this->connection->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', ['order-1']));
            self::assertCount(1, $this->movements($a));
        }
    }

    public function testRepeatedReservationAnswersWithTheCurrentStatusWithoutChangingStock(): void
    {
        $a = StockFixture::stock($this->connection, 10);
        $reserve = new ReserveStock('order-1', [new ReserveStockLine($a, 4)]);
        $this->dispatch($reserve);
        self::assertSame('active', $this->dispatch($reserve)->status);

        $this->dispatch(new CommitReservation('order-1'));
        $state = $this->stockRow($a);
        $movements = $this->movements($a);

        self::assertEquals(new ReservationView('order-1', 'committed', [new ReservationLineView($a, 4)]), $this->dispatch($reserve));
        self::assertSame(['physical' => 6, 'reserved' => 0, 'version' => 3], $state);
        self::assertSame($state, $this->stockRow($a));
        self::assertSame($movements, $this->movements($a));
    }

    public function testReleaseAndCommitThroughTheBus(): void
    {
        $a = StockFixture::stock($this->connection, 10);
        $this->dispatch(new ReserveStock('order-1', [new ReserveStockLine($a, 4)]));
        $this->dispatch(new ReserveStock('order-2', [new ReserveStockLine($a, 3)]));

        self::assertSame('released', $this->dispatch(new ReleaseReservation('order-1'))->status);
        self::assertSame('released', $this->dispatch(new ReleaseReservation('order-1'))->status);
        self::assertSame('committed', $this->dispatch(new CommitReservation('order-2'))->status);
        self::assertSame('committed', $this->dispatch(new CommitReservation('order-2'))->status);

        self::assertSame(['physical' => 7, 'reserved' => 0, 'version' => 5], $this->stockRow($a));
        self::assertSame(
            [['reservation', 0, 4, 10, 4, 'order-1', 'system'], ['reservation', 0, 3, 10, 7, 'order-2', 'system'], ['release', 0, -4, 10, 3, 'order-1', 'system'], ['commit', -3, -3, 7, 0, 'order-2', 'system']],
            $this->movements($a),
        );

        try {
            $this->dispatch(new CommitReservation('order-1'));
            self::fail('Expected ReservationStateConflict.');
        } catch (ReservationStateConflict) {
        }
        try {
            $this->dispatch(new ReleaseReservation('order-3'));
            self::fail('Expected ReservationNotFound.');
        } catch (ReservationNotFound) {
        }
    }

    /**
     * A trigger, created inside the test's transaction and so rolled back with it, makes every history write fail.
     */
    private function refuseHistoryWrites(): void
    {
        $this->connection->executeStatement("CREATE FUNCTION inventory_test_refuse_movement() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'history write refused by the test'; END \$\$");
        $this->connection->executeStatement('CREATE TRIGGER inventory_test_refuse_movement BEFORE INSERT ON inventory_stock_movement FOR EACH ROW EXECUTE FUNCTION inventory_test_refuse_movement()');
    }

    /**
     * @param array<string, array{int, int, int}> $stocks product id => physical, reserved, version as before
     */
    private function assertNothingReserved(string $key, array $stocks): void
    {
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_reservation WHERE reservation_key = ?', [$key]));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_reservation_line WHERE reservation_key = ?', [$key]));
        foreach ($stocks as $id => [$physical, $reserved, $version]) {
            self::assertSame(['physical' => $physical, 'reserved' => $reserved, 'version' => $version], $this->stockRow($id));
            self::assertSame([], $this->movements($id));
        }
    }

    private function dispatch(object $command): ReservationView
    {
        try {
            $envelope = $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $wrapped = array_values($failure->getWrappedExceptions());

            throw 1 === \count($wrapped) ? $wrapped[0] : $failure;
        }
        $result = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ReservationView::class, $result);

        return $result;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function stockRow(string $variantId): array|false
    {
        return $this->connection->fetchAssociative('SELECT physical, reserved, version FROM inventory_stock_item WHERE variant_id = ?', [$variantId]);
    }

    /**
     * @return list<list<mixed>> the movements other than the fixture's receipt, oldest first
     */
    private function movements(string $variantId): array
    {
        return $this->connection->fetchAllNumeric(
            "SELECT type, physical_delta, reserved_delta, physical_after, reserved_after, reference, source FROM inventory_stock_movement WHERE variant_id = ? AND type <> 'receipt' ORDER BY id",
            [$variantId],
        );
    }

    /**
     * @param array<string, int> $lines
     *
     * @return list<ReservationLineView>
     */
    private static function sortedLines(array $lines): array
    {
        ksort($lines, \SORT_STRING);

        return array_map(static fn (string $id, int $q): ReservationLineView => new ReservationLineView($id, $q), array_keys($lines), array_values($lines));
    }
}
