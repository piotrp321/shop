<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockInvariantViolation;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockMovement;
use App\Inventory\Domain\Stock\StockMovementType;
use App\Inventory\Domain\Stock\StockShortage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockItemTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    public function testReconstitutedItemHasNoMovementsAndComputesAvailable(): void
    {
        $updatedAt = new \DateTimeImmutable('2026-11-01 10:00:00+00:00');
        $item = StockItem::reconstitute(ProductId::fromString(self::ID), 10, 4, 3, $updatedAt);

        self::assertSame(self::ID, $item->id()->toString());
        self::assertSame(10, $item->physical());
        self::assertSame(4, $item->reserved());
        self::assertSame(6, $item->available());
        self::assertSame(3, $item->version());
        self::assertSame($updatedAt, $item->updatedAt());
        self::assertSame([], $item->releaseMovements());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function brokenInvariants(): iterable
    {
        yield 'negative reserved' => [5, -1];
        yield 'reserved above physical' => [5, 6];
        yield 'physical above the limit' => [1_000_001, 0];
        yield 'negative physical' => [-1, 0];
    }

    #[DataProvider('brokenInvariants')]
    public function testReconstituteRefusesAStateBreakingTheInvariant(int $physical, int $reserved): void
    {
        $this->expectException(StockInvariantViolation::class);

        StockItem::reconstitute(ProductId::fromString(self::ID), $physical, $reserved, 1, new \DateTimeImmutable());
    }

    public function testTheLimitsThemselvesAreValid(): void
    {
        $full = StockItem::reconstitute(ProductId::fromString(self::ID), 1_000_000, 1_000_000, 1, new \DateTimeImmutable());
        $empty = StockItem::reconstitute(ProductId::fromString(self::ID), 0, 0, 1, new \DateTimeImmutable());

        self::assertSame(0, $full->available());
        self::assertSame(0, $empty->available());
    }

    public function testReserveIncreasesReservedAndRecordsOneSystemMovement(): void
    {
        $item = $this->item(physical: 10, reserved: 2, version: 4);
        $before = new \DateTimeImmutable();

        $item->reserve(3, ReservationKey::fromString('order-1'));

        self::assertSame(10, $item->physical());
        self::assertSame(5, $item->reserved());
        self::assertSame(5, $item->available());
        self::assertSame(5, $item->version());
        $movement = $this->onlyMovement($item);
        self::assertSame(StockMovementType::Reservation, $movement->type());
        self::assertSame(self::ID, $movement->productId()->toString());
        self::assertSame(0, $movement->physicalDelta());
        self::assertSame(3, $movement->reservedDelta());
        self::assertSame(10, $movement->physicalAfter());
        self::assertSame(5, $movement->reservedAfter());
        self::assertNull($movement->reason());
        self::assertNull($movement->note());
        self::assertSame('order-1', $movement->reference());
        self::assertSame(StockChangeSource::System, $movement->origin()->source());
        self::assertNull($movement->origin()->actorId());
        self::assertNull($movement->origin()->actorName());
        self::assertGreaterThanOrEqual($before, $movement->occurredAt());
        self::assertEquals($movement->occurredAt(), $item->updatedAt());
    }

    public function testReserveOfExactlyTheAvailableQuantityLeavesNothingAvailable(): void
    {
        $item = $this->item(physical: 7, reserved: 4);

        $item->reserve(3, ReservationKey::fromString('order-1'));

        self::assertSame(0, $item->available());
    }

    public function testReserveOfMoreThanAvailableIsAShortageAndChangesNothing(): void
    {
        $item = $this->item(physical: 7, reserved: 4, version: 2);

        try {
            $item->reserve(4, ReservationKey::fromString('order-1'));
            self::fail('Expected StockShortage.');
        } catch (StockShortage $shortage) {
            self::assertSame(self::ID, $shortage->productId()->toString());
            self::assertSame(4, $shortage->requested());
            self::assertSame(3, $shortage->available());
        }

        self::assertSame(4, $item->reserved());
        self::assertSame(2, $item->version());
        self::assertSame([], $item->releaseMovements());
    }

    #[DataProvider('nonPositiveQuantities')]
    public function testOperationsRefuseANonPositiveQuantity(string $operation, int $quantity): void
    {
        $item = $this->item(physical: 10, reserved: 5);

        $this->expectException(\InvalidArgumentException::class);

        $item->{$operation}($quantity, ReservationKey::fromString('order-1'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function nonPositiveQuantities(): iterable
    {
        foreach (['reserve', 'release', 'commit'] as $operation) {
            yield $operation.' 0' => [$operation, 0];
            yield $operation.' -1' => [$operation, -1];
        }
    }

    public function testReleaseDecreasesReservedAndRecordsOneMovement(): void
    {
        $item = $this->item(physical: 10, reserved: 5, version: 7);

        $item->release(2, ReservationKey::fromString('order-1'));

        self::assertSame(10, $item->physical());
        self::assertSame(3, $item->reserved());
        self::assertSame(8, $item->version());
        $movement = $this->onlyMovement($item);
        self::assertSame(StockMovementType::Release, $movement->type());
        self::assertSame(0, $movement->physicalDelta());
        self::assertSame(-2, $movement->reservedDelta());
        self::assertSame(10, $movement->physicalAfter());
        self::assertSame(3, $movement->reservedAfter());
        self::assertSame('order-1', $movement->reference());
        self::assertSame(StockChangeSource::System, $movement->origin()->source());
    }

    public function testCommitDecreasesPhysicalAndReservedAndRecordsOneMovement(): void
    {
        $item = $this->item(physical: 10, reserved: 5, version: 7);

        $item->commit(5, ReservationKey::fromString('order-1'));

        self::assertSame(5, $item->physical());
        self::assertSame(0, $item->reserved());
        self::assertSame(5, $item->available());
        self::assertSame(8, $item->version());
        $movement = $this->onlyMovement($item);
        self::assertSame(StockMovementType::Commit, $movement->type());
        self::assertSame(-5, $movement->physicalDelta());
        self::assertSame(-5, $movement->reservedDelta());
        self::assertSame(5, $movement->physicalAfter());
        self::assertSame(0, $movement->reservedAfter());
        self::assertSame('order-1', $movement->reference());
    }

    #[DataProvider('releasingOperations')]
    public function testReleaseAndCommitRefuseMoreThanIsReserved(string $operation): void
    {
        $item = $this->item(physical: 10, reserved: 2, version: 3);

        try {
            $item->{$operation}(3, ReservationKey::fromString('order-1'));
            self::fail('Expected StockInvariantViolation.');
        } catch (StockInvariantViolation) {
        }

        self::assertSame(10, $item->physical());
        self::assertSame(2, $item->reserved());
        self::assertSame(3, $item->version());
        self::assertSame([], $item->releaseMovements());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function releasingOperations(): iterable
    {
        yield 'release' => ['release'];
        yield 'commit' => ['commit'];
    }

    public function testChangesBeforeASaveShareOneVersionStepAndAreReleasedOnce(): void
    {
        $item = $this->item(physical: 10, reserved: 0, version: 1);

        $item->reserve(4, ReservationKey::fromString('order-1'));
        $item->commit(4, ReservationKey::fromString('order-1'));

        self::assertSame(2, $item->version());
        $movements = $item->releaseMovements();
        self::assertSame([StockMovementType::Reservation, StockMovementType::Commit], array_map(static fn (StockMovement $m): StockMovementType => $m->type(), $movements));
        self::assertSame([], $item->releaseMovements());

        $item->reserve(1, ReservationKey::fromString('order-2'));
        self::assertSame(3, $item->version());
    }

    private function item(int $physical, int $reserved, int $version = 1): StockItem
    {
        return StockItem::reconstitute(ProductId::fromString(self::ID), $physical, $reserved, $version, new \DateTimeImmutable('2026-11-01 10:00:00+00:00'));
    }

    private function onlyMovement(StockItem $item): StockMovement
    {
        $movements = $item->releaseMovements();
        self::assertCount(1, $movements);

        return $movements[0];
    }
}
