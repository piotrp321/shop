<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\CorrectionReason;
use App\Inventory\Domain\Stock\ReceiptKey;
use App\Inventory\Domain\Stock\StockChangeOrigin;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockLevelChanged;
use App\Inventory\Domain\Stock\StockNote;
use App\Inventory\Domain\Stock\VariantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * StockLevelChanged: recorded only when an operation changes the availability level of the variant.
 */
final class StockItemLevelChangeTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    /**
     * @return iterable<string, array{int, int, \Closure(StockItem): void, AvailabilityLevel, StockChangeSource}>
     */
    public static function levelChanges(): iterable
    {
        yield 'out_of_stock → low_stock by a receipt' => [0, 0, self::receive(3), AvailabilityLevel::LowStock, StockChangeSource::Staff];
        yield 'out_of_stock → in_stock by a seed receipt' => [0, 0, self::receive(10, StockChangeOrigin::seed()), AvailabilityLevel::InStock, StockChangeSource::Seed];
        yield 'low_stock → in_stock by a receipt' => [5, 0, self::receive(1), AvailabilityLevel::InStock, StockChangeSource::Staff];
        yield 'low_stock → out_of_stock by a reservation' => [5, 2, self::reserve(3), AvailabilityLevel::OutOfStock, StockChangeSource::System];
        yield 'in_stock → low_stock by a reservation' => [10, 0, self::reserve(5), AvailabilityLevel::LowStock, StockChangeSource::System];
        yield 'in_stock → out_of_stock by a correction' => [10, 0, self::correct(0), AvailabilityLevel::OutOfStock, StockChangeSource::Staff];
        yield 'out_of_stock → low_stock by a release' => [4, 4, self::release(1), AvailabilityLevel::LowStock, StockChangeSource::System];
        yield 'low_stock → in_stock by a release' => [10, 6, self::release(2), AvailabilityLevel::InStock, StockChangeSource::System];
        yield 'in_stock → low_stock by a correction' => [10, 0, self::correct(5), AvailabilityLevel::LowStock, StockChangeSource::Staff];
        yield 'low_stock → out_of_stock by a correction' => [3, 1, self::correct(1), AvailabilityLevel::OutOfStock, StockChangeSource::Staff];
    }

    /**
     * @param \Closure(StockItem): void $operation
     */
    #[DataProvider('levelChanges')]
    public function testAnOperationChangingTheLevelRecordsTheNewLevelWithItsSource(int $physical, int $reserved, \Closure $operation, AvailabilityLevel $level, StockChangeSource $source): void
    {
        $item = $this->item($physical, $reserved);

        $operation($item);

        $events = $item->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(StockLevelChanged::class, $events[0]);
        self::assertSame(self::ID, $events[0]->variantId);
        self::assertSame($level, $events[0]->level);
        self::assertSame($source, $events[0]->source);
        self::assertEquals($item->updatedAt(), $events[0]->occurredAt());
        self::assertSame([], $item->releaseEvents());
    }

    /**
     * @return iterable<string, array{int, int, \Closure(StockItem): void}>
     */
    public static function sameLevel(): iterable
    {
        yield 'a commit (available does not move)' => [10, 4, self::commit(4)];
        yield 'a receipt within in_stock' => [10, 0, self::receive(5)];
        yield 'a receipt within low_stock' => [1, 0, self::receive(2)];
        yield 'a reservation within in_stock' => [20, 0, self::reserve(5)];
        yield 'a release within low_stock' => [5, 3, self::release(1)];
        yield 'a correction within in_stock' => [20, 0, self::correct(15)];
        yield 'a correction to the counted quantity' => [3, 0, self::correct(3)];
        yield 'a commit within out_of_stock' => [4, 4, self::commit(2)];
    }

    /**
     * @param \Closure(StockItem): void $operation
     */
    #[DataProvider('sameLevel')]
    public function testAnOperationKeepingTheLevelRecordsNothing(int $physical, int $reserved, \Closure $operation): void
    {
        $item = $this->item($physical, $reserved);

        $operation($item);

        self::assertSame([], $item->releaseEvents());
    }

    public function testAStoredStockRecordsNothing(): void
    {
        self::assertSame([], $this->item(0, 0)->releaseEvents());
        self::assertSame([], StockItem::unstored(VariantId::fromString(self::ID))->releaseEvents());
    }

    public function testChangesBeforeTheNextSaveShareOneEventWithTheLastLevel(): void
    {
        $item = $this->item(0, 0);

        self::receive(3)($item);
        self::receive(10, StockChangeOrigin::seed())($item);

        $events = $item->releaseEvents();
        self::assertCount(1, $events);
        self::assertSame(AvailabilityLevel::InStock, $events[0]->level);
        self::assertSame(StockChangeSource::Seed, $events[0]->source);

        self::reserve(13)($item);
        self::assertSame(AvailabilityLevel::OutOfStock, $item->releaseEvents()[0]->level ?? null);
    }

    private function item(int $physical, int $reserved): StockItem
    {
        return StockItem::reconstitute(VariantId::fromString(self::ID), $physical, $reserved, 1, new \DateTimeImmutable('2026-11-01 10:00:00+00:00'));
    }

    /**
     * @return \Closure(StockItem): void
     */
    private static function receive(int $quantity, ?StockChangeOrigin $origin = null): \Closure
    {
        return static function (StockItem $item) use ($quantity, $origin): void {
            $item->receive($quantity, ReceiptKey::fromString('receipt-'.bin2hex(random_bytes(4))), StockNote::none(), $origin ?? StockChangeOrigin::staff('sub-1', 'Wanda Magazyn'));
        };
    }

    /**
     * @return \Closure(StockItem): void
     */
    private static function correct(int $counted): \Closure
    {
        return static function (StockItem $item) use ($counted): void {
            $item->correct($counted, CorrectionReason::InventoryCount, StockNote::none(), StockChangeOrigin::staff('sub-1', 'Wanda Magazyn'));
        };
    }

    /**
     * @return \Closure(StockItem): void
     */
    private static function reserve(int $quantity): \Closure
    {
        return static fn (StockItem $item) => $item->reserve($quantity, ReservationKey::fromString('order-1'));
    }

    /**
     * @return \Closure(StockItem): void
     */
    private static function release(int $quantity): \Closure
    {
        return static fn (StockItem $item) => $item->release($quantity, ReservationKey::fromString('order-1'));
    }

    /**
     * @return \Closure(StockItem): void
     */
    private static function commit(int $quantity): \Closure
    {
        return static fn (StockItem $item) => $item->commit($quantity, ReservationKey::fromString('order-1'));
    }
}
