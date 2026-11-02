<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\CorrectStock;
use App\Inventory\Application\Command\CorrectStockHandler;
use App\Inventory\Application\Command\StockView;
use App\Inventory\Domain\Stock\CorrectionBelowReserved;
use App\Inventory\Domain\Stock\InvalidCorrectionReason;
use App\Inventory\Domain\Stock\InvalidStockNote;
use App\Inventory\Domain\Stock\InvalidStockQuantity;
use App\Inventory\Domain\Stock\ProductNotFound;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockModifiedConcurrently;
use App\Inventory\Domain\Stock\StockMovementType;
use App\Tests\Unit\Inventory\InMemoryProductCatalog;
use App\Tests\Unit\Inventory\InMemoryReservationRepository;
use App\Tests\Unit\Inventory\InMemoryStockItemRepository;
use App\Tests\Unit\Inventory\InMemoryTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CorrectStockHandlerTest extends TestCase
{
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    private InMemoryStockItemRepository $stock;
    private InMemoryProductCatalog $catalog;
    private InMemoryTransaction $transaction;
    private CorrectStockHandler $handler;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockItemRepository();
        $this->catalog = new InMemoryProductCatalog();
        $this->catalog->add(self::A);
        $this->transaction = new InMemoryTransaction($this->stock, new InMemoryReservationRepository());
        $this->handler = new CorrectStockHandler($this->stock, $this->catalog);
    }

    public function testSetsTheCountedPhysicalOfTheVersionSeenAndRecordsTheStaffMember(): void
    {
        $this->stock->store(self::A, 10, 3, version: 4);

        $view = $this->correct(self::A, 7, 4, 'damage', ' Zalane ');

        $this->assertView([self::A, 7, 3, 4, 'low_stock', 5], $view);
        self::assertNotNull($view->updatedAt);
        self::assertSame([7, 3, 5], $this->state(self::A));
        self::assertCount(1, $this->stock->movements);
        $movement = $this->stock->movements[0];
        self::assertSame(StockMovementType::Correction, $movement->type());
        self::assertSame([-3, 7, 'damage', 'Zalane', null], [$movement->physicalDelta(), $movement->physicalAfter(), $movement->reason(), $movement->note(), $movement->reference()]);
        self::assertSame([StockChangeSource::Staff, 'sub-1', 'Wanda Magazyn'], [$movement->origin()->source(), $movement->origin()->actorId(), $movement->origin()->actorName()]);
        self::assertSame([[self::A]], $this->stock->locks);
    }

    public function testTheCurrentPhysicalChangesNeitherTheVersionNorTheHistory(): void
    {
        $this->stock->store(self::A, 10, 3, version: 4);

        $view = $this->correct(self::A, 10, 4, 'inventory_count', null);

        $this->assertView([self::A, 10, 3, 7, 'in_stock', 4], $view);
        self::assertSame([10, 3, 4], $this->state(self::A));
        self::assertSame([], $this->stock->movements);
        self::assertSame(0, $this->stock->writes);
    }

    public function testTheFirstCorrectionOfAProductWithoutStockCreatesItAtVersionOne(): void
    {
        $view = $this->correct(self::A, 5, null, 'inventory_count', null);

        $this->assertView([self::A, 5, 0, 5, 'low_stock', 1], $view);
        self::assertSame([5, 0, 1], $this->state(self::A));
        self::assertSame(5, $this->stock->movements[0]->physicalDelta());
    }

    public function testCountingNothingForAProductWithoutStockStoresNothing(): void
    {
        $view = $this->correct(self::A, 0, null, 'loss', null);

        $this->assertView([self::A, 0, 0, 0, 'out_of_stock', null], $view);
        self::assertNull($view->updatedAt);
        self::assertNull($this->stock->stored(self::A));
        self::assertSame([], $this->stock->movements);
    }

    /**
     * @return iterable<string, array{int|null}>
     */
    public static function staleVersions(): iterable
    {
        yield 'null for a stored stock' => [null];
        yield 'an older version' => [3];
        yield 'a newer version' => [5];
    }

    #[DataProvider('staleVersions')]
    public function testAVersionOtherThanTheStoredOneIsAConflictAndChangesNothing(?int $version): void
    {
        $this->stock->store(self::A, 10, 0, version: 4);

        try {
            $this->correct(self::A, 8, $version, 'loss', null);
            self::fail('Expected StockModifiedConcurrently.');
        } catch (StockModifiedConcurrently $conflict) {
            self::assertSame('The stock was modified concurrently. Reload it and try again.', $conflict->getMessage());
        }

        self::assertSame([10, 0, 4], $this->state(self::A));
        self::assertSame([], $this->stock->movements);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function countsForAProductWithoutStock(): iterable
    {
        yield 'a change' => [5];
        yield 'nothing counted' => [0];
    }

    #[DataProvider('countsForAProductWithoutStock')]
    public function testAVersionForAProductWithoutStockIsAConflict(int $counted): void
    {
        try {
            $this->correct(self::A, $counted, 1, 'inventory_count', null);
            self::fail('Expected StockModifiedConcurrently.');
        } catch (StockModifiedConcurrently) {
        }

        self::assertNull($this->stock->stored(self::A));
    }

    public function testCountingLessThanIsReservedIsAConflictNamingTheReservedQuantity(): void
    {
        $this->stock->store(self::A, 10, 6, version: 2);

        try {
            $this->correct(self::A, 5, 2, 'loss', null);
            self::fail('Expected CorrectionBelowReserved.');
        } catch (CorrectionBelowReserved $conflict) {
            self::assertSame('Stan fizyczny nie może być niższy niż zarezerwowany (6 szt.).', $conflict->getMessage());
        }

        self::assertSame([10, 6, 2], $this->state(self::A));
        self::assertSame([], $this->stock->movements);
    }

    public function testAnUnknownProductIsNotFoundAndNothingIsCreated(): void
    {
        $unknown = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a69';

        try {
            $this->correct($unknown, 5, null, 'inventory_count', null);
            self::fail('Expected ProductNotFound.');
        } catch (ProductNotFound) {
        }

        self::assertNull($this->stock->stored($unknown));
        self::assertSame([], $this->stock->locks);
    }

    /**
     * @return iterable<string, array{int, string, string|null, class-string<\Throwable>}>
     */
    public static function invalidCorrections(): iterable
    {
        yield 'a negative count' => [-1, 'loss', null, InvalidStockQuantity::class];
        yield 'over one million' => [1_000_001, 'loss', null, InvalidStockQuantity::class];
        yield 'an unknown reason' => [5, 'theft', null, InvalidCorrectionReason::class];
        yield 'another reason without a note' => [5, 'other', '  ', InvalidStockNote::class];
        yield 'a note over 500 characters' => [5, 'loss', str_repeat('n', 501), InvalidStockNote::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('invalidCorrections')]
    public function testAnInvalidCorrectionIsRefusedAndChangesNothing(int $counted, string $reason, ?string $note, string $expected): void
    {
        $this->stock->store(self::A, 10, 0, version: 2);

        $this->expectException($expected);

        try {
            $this->correct(self::A, $counted, 2, $reason, $note);
        } finally {
            self::assertSame([10, 0, 2], $this->state(self::A));
            self::assertSame([], $this->stock->movements);
        }
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('invalidCorrections')]
    public function testAnInvalidCorrectionOfAProductWithoutStockCreatesNothing(int $counted, string $reason, ?string $note, string $expected): void
    {
        $this->expectException($expected);

        try {
            $this->correct(self::A, $counted, null, $reason, $note);
        } finally {
            self::assertNull($this->stock->stored(self::A));
        }
    }

    public function testAnUnknownReasonIsInvalidInputWithoutTheValueInTheMessage(): void
    {
        $this->stock->store(self::A, 10);

        try {
            $this->correct(self::A, 5, 1, '<script>', null);
            self::fail('Expected InvalidCorrectionReason.');
        } catch (InvalidCorrectionReason $invalid) {
            self::assertSame('The reason of a correction must be inventory_count, damage, loss or other.', $invalid->getMessage());
        }
    }

    private function correct(string $productId, int $counted, ?int $version, string $reason, ?string $note): StockView
    {
        $command = CorrectStock::byStaff($productId, $counted, $version, $reason, $note, 'sub-1', 'Wanda Magazyn');

        return $this->transaction->run(fn (): StockView => ($this->handler)($command));
    }

    /**
     * @param array{string, int, int, int, string, int|null} $expected productId, physical, reserved, available, level, version
     */
    private function assertView(array $expected, StockView $view): void
    {
        self::assertSame($expected, [$view->productId, $view->physical, $view->reserved, $view->available, $view->level, $view->version]);
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
}
