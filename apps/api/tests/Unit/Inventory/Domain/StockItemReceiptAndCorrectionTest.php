<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Stock\CorrectionBelowReserved;
use App\Inventory\Domain\Stock\CorrectionReason;
use App\Inventory\Domain\Stock\InvalidStockNote;
use App\Inventory\Domain\Stock\InvalidStockQuantity;
use App\Inventory\Domain\Stock\PhysicalStockLimitExceeded;
use App\Inventory\Domain\Stock\ReceiptKey;
use App\Inventory\Domain\Stock\StockChangeOrigin;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockMovement;
use App\Inventory\Domain\Stock\StockMovementType;
use App\Inventory\Domain\Stock\StockNote;
use App\Inventory\Domain\Stock\VariantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * StockItem::receive and StockItem::correct: the staff operations on a stock.
 */
final class StockItemReceiptAndCorrectionTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string OTHER_ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';

    public function testAnUnstoredStockHasNothingAndNoVersion(): void
    {
        $item = StockItem::unstored(VariantId::fromString(self::ID));

        self::assertSame(self::ID, $item->id()->toString());
        self::assertSame([0, 0, 0, 0], [$item->physical(), $item->reserved(), $item->available(), $item->version()]);
        self::assertSame([], $item->releaseMovements());
    }

    public function testReceiveAddsToPhysicalAndRecordsOneReceiptWithTheKeyNoteAndActor(): void
    {
        $item = $this->item(physical: 10, reserved: 4, version: 3);
        $before = new \DateTimeImmutable();

        $item->receive(25, ReceiptKey::fromString('delivery-1'), StockNote::fromNullable(' Dostawa '), $this->staff());

        self::assertSame([35, 4, 31, 4], [$item->physical(), $item->reserved(), $item->available(), $item->version()]);
        $movement = $this->onlyMovement($item);
        self::assertSame(StockMovementType::Receipt, $movement->type());
        self::assertSame(self::ID, $movement->variantId()->toString());
        self::assertSame([25, 0, 35, 4], [$movement->physicalDelta(), $movement->reservedDelta(), $movement->physicalAfter(), $movement->reservedAfter()]);
        self::assertNull($movement->reason());
        self::assertSame('Dostawa', $movement->note());
        self::assertSame('delivery-1', $movement->reference());
        self::assertSame(StockChangeSource::Staff, $movement->origin()->source());
        self::assertSame(['sub-1', 'Wanda Magazyn'], [$movement->origin()->actorId(), $movement->origin()->actorName()]);
        self::assertGreaterThanOrEqual($before, $movement->occurredAt());
        self::assertEquals($movement->occurredAt(), $item->updatedAt());
    }

    public function testTheFirstReceiptOfAnUnstoredStockGivesItVersionOne(): void
    {
        $item = StockItem::unstored(VariantId::fromString(self::ID));

        $item->receive(1, ReceiptKey::fromString('seed:1'), StockNote::none(), StockChangeOrigin::seed());

        self::assertSame([1, 0, 1], [$item->physical(), $item->reserved(), $item->version()]);
        $movement = $this->onlyMovement($item);
        self::assertSame(StockChangeSource::Seed, $movement->origin()->source());
        self::assertNull($movement->note());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function receiptQuantitiesOutOfRange(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above 100 000' => [100_001];
    }

    #[DataProvider('receiptQuantitiesOutOfRange')]
    public function testReceiveRefusesAQuantityOutsideOneToOneHundredThousand(int $quantity): void
    {
        $item = $this->item(physical: 10, reserved: 0, version: 2);

        try {
            $item->receive($quantity, ReceiptKey::fromString('k'), StockNote::none(), $this->staff());
            self::fail('Expected InvalidStockQuantity.');
        } catch (InvalidStockQuantity $invalid) {
            self::assertSame('A receipt must add 1 to 100000 units.', $invalid->getMessage());
        }

        $this->assertUnchanged($item, 10, 0, 2);
    }

    public function testReceiveAcceptsTheLimitsOfTheQuantity(): void
    {
        $item = $this->item(physical: 0, reserved: 0);

        $item->receive(1, ReceiptKey::fromString('a'), StockNote::none(), $this->staff());
        $item->receive(100_000, ReceiptKey::fromString('b'), StockNote::none(), $this->staff());

        self::assertSame(100_001, $item->physical());
    }

    public function testReceiveUpToTheLimitOfOneMillionIsAccepted(): void
    {
        $item = $this->item(physical: 900_000, reserved: 0);

        $item->receive(100_000, ReceiptKey::fromString('k'), StockNote::none(), $this->staff());

        self::assertSame(1_000_000, $item->physical());
    }

    public function testReceiveBeyondOneMillionIsRefusedAndChangesNothing(): void
    {
        $item = $this->item(physical: 900_001, reserved: 5, version: 6);

        try {
            $item->receive(100_000, ReceiptKey::fromString('k'), StockNote::none(), $this->staff());
            self::fail('Expected PhysicalStockLimitExceeded.');
        } catch (PhysicalStockLimitExceeded $exceeded) {
            self::assertSame('Stan fizyczny nie może przekroczyć 1 000 000 szt.', $exceeded->getMessage());
        }

        $this->assertUnchanged($item, 900_001, 5, 6);
    }

    public function testCorrectSetsTheCountedPhysicalAndRecordsOneCorrection(): void
    {
        $item = $this->item(physical: 10, reserved: 4, version: 3);

        $changed = $item->correct(6, CorrectionReason::Damage, StockNote::fromNullable('Zalane'), $this->staff());

        self::assertTrue($changed);
        self::assertSame([6, 4, 2, 4], [$item->physical(), $item->reserved(), $item->available(), $item->version()]);
        $movement = $this->onlyMovement($item);
        self::assertSame(StockMovementType::Correction, $movement->type());
        self::assertSame([-4, 0, 6, 4], [$movement->physicalDelta(), $movement->reservedDelta(), $movement->physicalAfter(), $movement->reservedAfter()]);
        self::assertSame('damage', $movement->reason());
        self::assertSame('Zalane', $movement->note());
        self::assertNull($movement->reference());
        self::assertSame(['sub-1', 'Wanda Magazyn'], [$movement->origin()->actorId(), $movement->origin()->actorName()]);
        self::assertEquals($movement->occurredAt(), $item->updatedAt());
    }

    public function testCorrectUpwardsRecordsAPositiveDelta(): void
    {
        $item = $this->item(physical: 3, reserved: 0);

        self::assertTrue($item->correct(1_000_000, CorrectionReason::InventoryCount, StockNote::none(), $this->staff()));

        self::assertSame(999_997, $this->onlyMovement($item)->physicalDelta());
    }

    public function testCorrectToTheCurrentPhysicalChangesNothing(): void
    {
        $item = $this->item(physical: 10, reserved: 4, version: 3);

        self::assertFalse($item->correct(10, CorrectionReason::InventoryCount, StockNote::fromNullable('Bez zmian'), $this->staff()));

        $this->assertUnchanged($item, 10, 4, 3);
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00:00+00:00'), $item->updatedAt());
    }

    public function testCorrectOfAnUnstoredStockToZeroChangesNothing(): void
    {
        $item = StockItem::unstored(VariantId::fromString(self::ID));

        self::assertFalse($item->correct(0, CorrectionReason::Loss, StockNote::none(), $this->staff()));

        $this->assertUnchanged($item, 0, 0, 0);
    }

    public function testCorrectDownToTheReservedQuantityIsAccepted(): void
    {
        $item = $this->item(physical: 10, reserved: 4);

        self::assertTrue($item->correct(4, CorrectionReason::Loss, StockNote::none(), $this->staff()));

        self::assertSame(0, $item->available());
    }

    public function testCorrectBelowTheReservedQuantityIsAConflictNamingIt(): void
    {
        $item = $this->item(physical: 10, reserved: 4, version: 3);

        try {
            $item->correct(3, CorrectionReason::Loss, StockNote::none(), $this->staff());
            self::fail('Expected CorrectionBelowReserved.');
        } catch (CorrectionBelowReserved $conflict) {
            self::assertSame('Stan fizyczny nie może być niższy niż zarezerwowany (4 szt.).', $conflict->getMessage());
        }

        $this->assertUnchanged($item, 10, 4, 3);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function countsOutOfRange(): iterable
    {
        yield 'negative' => [-1];
        yield 'above one million' => [1_000_001];
    }

    #[DataProvider('countsOutOfRange')]
    public function testCorrectRefusesACountOutsideZeroToOneMillion(int $counted): void
    {
        $item = $this->item(physical: 10, reserved: 0, version: 2);

        try {
            $item->correct($counted, CorrectionReason::InventoryCount, StockNote::none(), $this->staff());
            self::fail('Expected InvalidStockQuantity.');
        } catch (InvalidStockQuantity $invalid) {
            self::assertSame('A counted stock must be 0 to 1000000 units.', $invalid->getMessage());
        }

        $this->assertUnchanged($item, 10, 0, 2);
    }

    public function testCorrectForAnotherReasonNeedsANote(): void
    {
        $item = $this->item(physical: 10, reserved: 0, version: 2);

        try {
            $item->correct(8, CorrectionReason::Other, StockNote::fromNullable('   '), $this->staff());
            self::fail('Expected InvalidStockNote.');
        } catch (InvalidStockNote $invalid) {
            self::assertSame('A correction for another reason needs a note.', $invalid->getMessage());
        }

        $this->assertUnchanged($item, 10, 0, 2);
    }

    public function testCorrectForAnotherReasonWithANoteIsAccepted(): void
    {
        $item = $this->item(physical: 10, reserved: 0);

        self::assertTrue($item->correct(8, CorrectionReason::Other, StockNote::fromNullable('Zwrot do dostawcy'), $this->staff()));

        $movement = $this->onlyMovement($item);
        self::assertSame(['other', 'Zwrot do dostawcy'], [$movement->reason(), $movement->note()]);
    }

    public function testAReceiptAndAReservationBeforeASaveShareOneVersionStep(): void
    {
        $item = $this->item(physical: 0, reserved: 0, version: 5);

        $item->receive(3, ReceiptKey::fromString('k'), StockNote::none(), $this->staff());
        $item->reserve(2, ReservationKey::fromString('order-1'));
        $item->correct(4, CorrectionReason::InventoryCount, StockNote::none(), $this->staff());

        self::assertSame(6, $item->version());
        self::assertSame(
            [StockMovementType::Receipt, StockMovementType::Reservation, StockMovementType::Correction],
            array_map(static fn (StockMovement $m): StockMovementType => $m->type(), $item->releaseMovements()),
        );
    }

    /**
     * @return iterable<string, array{string, int, string|null, bool}>
     */
    public static function receiptComparisons(): iterable
    {
        yield 'the same variant, quantity and note' => [self::ID, 5, 'Dostawa', true];
        yield 'the same note with other white space' => [self::ID, 5, '  Dostawa ', true];
        yield 'another quantity' => [self::ID, 6, 'Dostawa', false];
        yield 'another note' => [self::ID, 5, 'Inna', false];
        yield 'no note' => [self::ID, 5, null, false];
        yield 'another variant' => [self::OTHER_ID, 5, 'Dostawa', false];
    }

    #[DataProvider('receiptComparisons')]
    public function testAReceiptIsRepeatedOnlyWithTheSameVariantQuantityAndNote(string $variantId, int $quantity, ?string $note, bool $repeated): void
    {
        $item = $this->item(physical: 0, reserved: 0);
        $item->receive(5, ReceiptKey::fromString('k'), StockNote::fromNullable('Dostawa'), $this->staff());
        $receipt = $this->onlyMovement($item);

        self::assertSame($repeated, $receipt->isReceiptOf(VariantId::fromString($variantId), $quantity, StockNote::fromNullable($note)));
    }

    public function testAMovementOtherThanAReceiptIsNoReceipt(): void
    {
        $item = $this->item(physical: 10, reserved: 0);
        $item->correct(5, CorrectionReason::InventoryCount, StockNote::none(), $this->staff());

        self::assertFalse($this->onlyMovement($item)->isReceiptOf(VariantId::fromString(self::ID), -5, StockNote::none()));
    }

    private function staff(): StockChangeOrigin
    {
        return StockChangeOrigin::staff('sub-1', 'Wanda Magazyn');
    }

    private function item(int $physical, int $reserved, int $version = 1): StockItem
    {
        return StockItem::reconstitute(VariantId::fromString(self::ID), $physical, $reserved, $version, new \DateTimeImmutable('2026-11-01 10:00:00+00:00'));
    }

    private function onlyMovement(StockItem $item): StockMovement
    {
        $movements = $item->releaseMovements();
        self::assertCount(1, $movements);

        return $movements[0];
    }

    private function assertUnchanged(StockItem $item, int $physical, int $reserved, int $version): void
    {
        self::assertSame([$physical, $reserved, $version], [$item->physical(), $item->reserved(), $item->version()]);
        self::assertSame([], $item->releaseMovements());
    }
}
