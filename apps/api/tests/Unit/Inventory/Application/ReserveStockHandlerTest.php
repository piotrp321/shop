<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\CommitReservation;
use App\Inventory\Application\Command\InsufficientStock;
use App\Inventory\Application\Command\InvalidReservation;
use App\Inventory\Application\Command\ReleaseReservation;
use App\Inventory\Application\Command\ReservationKeyReused;
use App\Inventory\Application\Command\ReservationLineView;
use App\Inventory\Application\Command\ReservationTransitionHandler;
use App\Inventory\Application\Command\ReservationView;
use App\Inventory\Application\Command\ReserveStock;
use App\Inventory\Application\Command\ReserveStockHandler;
use App\Inventory\Application\Command\ReserveStockLine;
use App\Inventory\Application\Command\Shortage;
use App\Inventory\Domain\Reservation\ReservationStatus;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockMovement;
use App\Inventory\Domain\Stock\StockMovementType;
use App\Tests\Unit\Inventory\InMemoryReservationRepository;
use App\Tests\Unit\Inventory\InMemoryStockItemRepository;
use App\Tests\Unit\Inventory\InMemoryTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReserveStockHandlerTest extends TestCase
{
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string C = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';

    private InMemoryStockItemRepository $stock;
    private InMemoryReservationRepository $reservations;
    private InMemoryTransaction $transaction;
    private ReserveStockHandler $handler;
    private ReservationTransitionHandler $transitions;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockItemRepository();
        $this->reservations = new InMemoryReservationRepository();
        $this->transaction = new InMemoryTransaction($this->stock, $this->reservations);
        $this->handler = new ReserveStockHandler($this->reservations, $this->stock);
        $this->transitions = new ReservationTransitionHandler($this->reservations, $this->stock);
    }

    public function testReservesEveryLineAndRecordsOneSystemMovementPerLine(): void
    {
        $this->stock->store(self::A, 10, 1, version: 3);
        $this->stock->store(self::B, 5);

        $view = $this->reserve('order-1', [self::B => 5, self::A => 2]);

        self::assertEquals(new ReservationView('order-1', 'active', [new ReservationLineView(self::A, 2), new ReservationLineView(self::B, 5)]), $view);
        self::assertSame([10, 3, 4], $this->state(self::A));
        self::assertSame([5, 5, 2], $this->state(self::B));
        self::assertSame(
            [[self::A, 'reservation', 0, 2, 10, 3, 'order-1', 'system'], [self::B, 'reservation', 0, 5, 5, 5, 'order-1', 'system']],
            array_map($this->movementRow(...), $this->stock->movements),
        );
        self::assertSame(ReservationStatus::Active, $this->reservations->stored('order-1')?->status());
    }

    public function testLocksTheProductsOnceInAscendingOrder(): void
    {
        $this->stock->store(self::A, 10);
        $this->stock->store(self::B, 10);
        $this->stock->store(self::C, 10);

        $this->reserve('order-1', [self::C => 1, self::A => 1, self::B => 1]);

        self::assertSame([[self::A, self::B, self::C]], $this->stock->locks);
    }

    public function testShortageOfAnyLineIsInsufficientStockListingEveryShortLineAndStoresNothing(): void
    {
        $this->stock->store(self::A, 10);
        $this->stock->store(self::B, 5, 4);
        // C has no stored stock: nothing available.

        try {
            $this->reserve('order-1', [self::C => 3, self::B => 2, self::A => 1]);
            self::fail('Expected InsufficientStock.');
        } catch (InsufficientStock $insufficient) {
            self::assertEquals([new Shortage(self::B, 2, 1), new Shortage(self::C, 3, 0)], $insufficient->shortages);
        }

        self::assertSame([10, 0, 1], $this->state(self::A));
        self::assertSame([5, 4, 1], $this->state(self::B));
        self::assertSame([], $this->stock->movements);
        self::assertSame(0, $this->stock->writes);
        self::assertNull($this->reservations->stored('order-1'));
    }

    public function testTheKeyOfARefusedReservationCanBeUsedLater(): void
    {
        $this->stock->store(self::A, 1);
        try {
            $this->reserve('order-1', [self::A => 2]);
            self::fail('Expected InsufficientStock.');
        } catch (InsufficientStock) {
        }

        $this->stock->store(self::A, 2, version: 2);
        $view = $this->reserve('order-1', [self::A => 2]);

        self::assertSame('active', $view->status);
        self::assertSame([2, 2, 3], $this->state(self::A));
    }

    public function testRepeatWithTheSameKeyAndLinesReturnsTheReservationWithoutChangingStock(): void
    {
        $this->stock->store(self::A, 10);
        $this->stock->store(self::B, 10);
        $first = $this->reserve('order-1', [self::A => 2, self::B => 1]);
        $movements = \count($this->stock->movements);

        $repeat = $this->reserve('order-1', [self::B => 1, self::A => 2]);

        self::assertEquals($first, $repeat);
        self::assertSame([10, 2, 2], $this->state(self::A));
        self::assertSame([10, 1, 2], $this->state(self::B));
        self::assertCount($movements, $this->stock->movements);
        self::assertCount(1, $this->stock->locks);
    }

    public function testRepeatAfterReleaseReturnsTheReleasedReservationWithoutChangingStock(): void
    {
        $this->stock->store(self::A, 10);
        $this->reserve('order-1', [self::A => 2]);
        $this->transaction->run(fn () => $this->transitions->release(new ReleaseReservation('order-1')));
        $movements = \count($this->stock->movements);

        $repeat = $this->reserve('order-1', [self::A => 2]);

        self::assertEquals(new ReservationView('order-1', 'released', [new ReservationLineView(self::A, 2)]), $repeat);
        self::assertSame([10, 0, 3], $this->state(self::A));
        self::assertCount($movements, $this->stock->movements);
    }

    public function testRepeatAfterCommitReturnsTheCommittedReservationWithoutChangingStock(): void
    {
        $this->stock->store(self::A, 10);
        $this->reserve('order-1', [self::A => 2]);
        $this->transaction->run(fn () => $this->transitions->commit(new CommitReservation('order-1')));
        $movements = \count($this->stock->movements);

        $repeat = $this->reserve('order-1', [self::A => 2]);

        self::assertEquals(new ReservationView('order-1', 'committed', [new ReservationLineView(self::A, 2)]), $repeat);
        self::assertSame([8, 0, 3], $this->state(self::A));
        self::assertCount($movements, $this->stock->movements);
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function otherLines(): iterable
    {
        yield 'other quantity' => [[self::A => 3, self::B => 1]];
        yield 'fewer products' => [[self::A => 2]];
        yield 'more products' => [[self::A => 2, self::B => 1, self::C => 1]];
        yield 'other product' => [[self::A => 2, self::C => 1]];
    }

    /**
     * @param array<string, int> $lines
     */
    #[DataProvider('otherLines')]
    public function testTheSameKeyWithOtherLinesIsRefused(array $lines): void
    {
        $this->stock->store(self::A, 10);
        $this->stock->store(self::B, 10);
        $this->stock->store(self::C, 10);
        $this->reserve('order-1', [self::A => 2, self::B => 1]);
        $movements = \count($this->stock->movements);

        try {
            $this->reserve('order-1', $lines);
            self::fail('Expected ReservationKeyReused.');
        } catch (ReservationKeyReused) {
        }

        self::assertSame([10, 2, 2], $this->state(self::A));
        self::assertSame([10, 1, 2], $this->state(self::B));
        self::assertSame([10, 0, 1], $this->state(self::C));
        self::assertCount($movements, $this->stock->movements);
    }

    /**
     * @return iterable<string, array{string, list<array{string, int}>}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'malformed key' => ['order 1', [[self::A, 1]]];
        yield 'empty key' => ['', [[self::A, 1]]];
        yield 'not a product id' => ['order-1', [['not-a-uuid', 1]]];
        yield 'no lines' => ['order-1', []];
        yield 'quantity 0' => ['order-1', [[self::A, 0]]];
        yield 'quantity 1001' => ['order-1', [[self::A, 1001]]];
        yield 'repeated product' => ['order-1', [[self::A, 1], [self::B, 1], [self::A, 1]]];
        yield '101 lines' => ['order-1', array_map(static fn (int $i): array => [\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), 1], range(1, 101))];
    }

    /**
     * @param list<array{string, int}> $lines
     */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestIsRefusedBeforeAnythingIsTouched(string $key, array $lines): void
    {
        $this->stock->store(self::A, 10);
        $this->stock->store(self::B, 10);

        try {
            ($this->handler)(new ReserveStock($key, array_map(static fn (array $l): ReserveStockLine => new ReserveStockLine($l[0], $l[1]), $lines)));
            self::fail('Expected InvalidReservation.');
        } catch (InvalidReservation) {
        }

        self::assertSame(0, $this->reservations->writes);
        self::assertSame([], $this->stock->locks);
    }

    public function testHundredLinesOfThousandUnitsAreAccepted(): void
    {
        $lines = [];
        for ($i = 1; $i <= 100; ++$i) {
            $id = \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i);
            $this->stock->store($id, 1000);
            $lines[$id] = 1000;
        }

        $view = $this->reserve('order-1', $lines);

        self::assertCount(100, $view->lines);
        self::assertCount(100, $this->stock->movements);
    }

    /**
     * @param array<string, int> $lines product id => quantity
     */
    private function reserve(string $key, array $lines): ReservationView
    {
        $command = new ReserveStock($key, array_map(
            static fn (string $id, int $quantity): ReserveStockLine => new ReserveStockLine($id, $quantity),
            array_keys($lines),
            array_values($lines),
        ));

        return $this->transaction->run(fn (): ReservationView => ($this->handler)($command));
    }

    /**
     * @return array{int, int, int} physical, reserved, version
     */
    private function state(string $productId): array
    {
        $item = $this->stock->stored($productId);
        self::assertNotNull($item);

        return [$item->physical(), $item->reserved(), $item->version()];
    }

    /**
     * @return array{string, string, int, int, int, int, ?string, string}
     */
    private function movementRow(StockMovement $m): array
    {
        self::assertNotSame(StockMovementType::Receipt, $m->type());
        self::assertSame(StockChangeSource::System, $m->origin()->source());

        return [$m->productId()->toString(), $m->type()->value, $m->physicalDelta(), $m->reservedDelta(), $m->physicalAfter(), $m->reservedAfter(), $m->reference(), $m->origin()->source()->value];
    }
}
