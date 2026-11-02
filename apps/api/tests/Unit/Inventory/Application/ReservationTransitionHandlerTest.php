<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\CommitReservation;
use App\Inventory\Application\Command\InvalidReservation;
use App\Inventory\Application\Command\ReleaseReservation;
use App\Inventory\Application\Command\ReservationLineView;
use App\Inventory\Application\Command\ReservationNotFound;
use App\Inventory\Application\Command\ReservationStateConflict;
use App\Inventory\Application\Command\ReservationTransitionHandler;
use App\Inventory\Application\Command\ReservationView;
use App\Inventory\Application\Command\ReserveStock;
use App\Inventory\Application\Command\ReserveStockHandler;
use App\Inventory\Application\Command\ReserveStockLine;
use App\Inventory\Domain\Stock\StockMovement;
use App\Tests\Unit\Inventory\InMemoryReservationRepository;
use App\Tests\Unit\Inventory\InMemoryStockItemRepository;
use App\Tests\Unit\Inventory\InMemoryTransaction;
use PHPUnit\Framework\TestCase;

final class ReservationTransitionHandlerTest extends TestCase
{
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';

    private InMemoryStockItemRepository $stock;
    private InMemoryReservationRepository $reservations;
    private InMemoryTransaction $transaction;
    private ReservationTransitionHandler $handler;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockItemRepository();
        $this->reservations = new InMemoryReservationRepository();
        $this->transaction = new InMemoryTransaction($this->stock, $this->reservations);
        $this->handler = new ReservationTransitionHandler($this->reservations, $this->stock);

        $this->stock->store(self::A, 10);
        $this->stock->store(self::B, 4);
        $reserve = new ReserveStockHandler($this->reservations, $this->stock);
        $this->transaction->run(static fn () => $reserve(new ReserveStock('order-1', [new ReserveStockLine(self::B, 3), new ReserveStockLine(self::A, 2)])));
        $this->stock->movements = [];
        $this->stock->locks = [];
    }

    public function testReleaseGivesBackTheReservedUnitsWithOneMovementPerLine(): void
    {
        $view = $this->release('order-1');

        self::assertEquals(new ReservationView('order-1', 'released', [new ReservationLineView(self::A, 2), new ReservationLineView(self::B, 3)]), $view);
        self::assertSame([10, 0, 3], $this->state(self::A));
        self::assertSame([4, 0, 3], $this->state(self::B));
        self::assertSame(
            [[self::A, 'release', 0, -2, 10, 0, 'order-1', 'system'], [self::B, 'release', 0, -3, 4, 0, 'order-1', 'system']],
            array_map($this->movementRow(...), $this->stock->movements),
        );
        self::assertSame([[self::A, self::B]], $this->stock->locks);
        self::assertSame('released', $this->reservations->stored('order-1')?->status()->value);
    }

    public function testCommitTakesTheReservedUnitsOffTheShelfWithOneMovementPerLine(): void
    {
        $view = $this->commit('order-1');

        self::assertEquals(new ReservationView('order-1', 'committed', [new ReservationLineView(self::A, 2), new ReservationLineView(self::B, 3)]), $view);
        self::assertSame([8, 0, 3], $this->state(self::A));
        self::assertSame([1, 0, 3], $this->state(self::B));
        self::assertSame(
            [[self::A, 'commit', -2, -2, 8, 0, 'order-1', 'system'], [self::B, 'commit', -3, -3, 1, 0, 'order-1', 'system']],
            array_map($this->movementRow(...), $this->stock->movements),
        );
        self::assertSame('committed', $this->reservations->stored('order-1')?->status()->value);
    }

    public function testRepeatedReleaseSucceedsWithoutChanges(): void
    {
        $first = $this->release('order-1');
        $movements = \count($this->stock->movements);
        $writes = $this->stock->writes;

        self::assertEquals($first, $this->release('order-1'));
        self::assertSame([10, 0, 3], $this->state(self::A));
        self::assertCount($movements, $this->stock->movements);
        self::assertSame($writes, $this->stock->writes);
    }

    public function testRepeatedCommitSucceedsWithoutChanges(): void
    {
        $first = $this->commit('order-1');
        $movements = \count($this->stock->movements);
        $writes = $this->stock->writes;

        self::assertEquals($first, $this->commit('order-1'));
        self::assertSame([8, 0, 3], $this->state(self::A));
        self::assertCount($movements, $this->stock->movements);
        self::assertSame($writes, $this->stock->writes);
    }

    public function testCommitOfAReleasedReservationIsAConflictAndChangesNothing(): void
    {
        $this->release('order-1');
        $movements = \count($this->stock->movements);

        try {
            $this->commit('order-1');
            self::fail('Expected ReservationStateConflict.');
        } catch (ReservationStateConflict) {
        }

        self::assertSame([10, 0, 3], $this->state(self::A));
        self::assertSame([4, 0, 3], $this->state(self::B));
        self::assertCount($movements, $this->stock->movements);
        self::assertSame('released', $this->reservations->stored('order-1')?->status()->value);
    }

    public function testReleaseOfACommittedReservationIsAConflictAndChangesNothing(): void
    {
        $this->commit('order-1');
        $movements = \count($this->stock->movements);

        try {
            $this->release('order-1');
            self::fail('Expected ReservationStateConflict.');
        } catch (ReservationStateConflict) {
        }

        self::assertSame([8, 0, 3], $this->state(self::A));
        self::assertCount($movements, $this->stock->movements);
        self::assertSame('committed', $this->reservations->stored('order-1')?->status()->value);
    }

    public function testUnknownKeyIsNotFound(): void
    {
        foreach ([$this->release(...), $this->commit(...)] as $transition) {
            try {
                $transition('order-2');
                self::fail('Expected ReservationNotFound.');
            } catch (ReservationNotFound) {
            }
        }
        self::assertSame([], $this->stock->locks);
    }

    public function testMalformedKeyIsInvalid(): void
    {
        foreach ([$this->release(...), $this->commit(...)] as $transition) {
            try {
                $transition('order 1');
                self::fail('Expected InvalidReservation.');
            } catch (InvalidReservation) {
            }
        }
        self::assertSame([], $this->stock->locks);
        self::assertSame('active', $this->reservations->stored('order-1')?->status()->value);
    }

    private function release(string $key): ReservationView
    {
        return $this->transaction->run(fn (): ReservationView => $this->handler->release(new ReleaseReservation($key)));
    }

    private function commit(string $key): ReservationView
    {
        return $this->transaction->run(fn (): ReservationView => $this->handler->commit(new CommitReservation($key)));
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
        return [$m->productId()->toString(), $m->type()->value, $m->physicalDelta(), $m->reservedDelta(), $m->physicalAfter(), $m->reservedAfter(), $m->reference(), $m->origin()->source()->value];
    }
}
