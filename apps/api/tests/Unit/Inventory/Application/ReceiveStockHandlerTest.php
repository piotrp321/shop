<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\ReceiveStock;
use App\Inventory\Application\Command\ReceiveStockHandler;
use App\Inventory\Application\Command\ReceiveStockResult;
use App\Inventory\Application\Command\StockView;
use App\Inventory\Domain\Stock\InvalidReceiptKey;
use App\Inventory\Domain\Stock\InvalidStockNote;
use App\Inventory\Domain\Stock\InvalidStockQuantity;
use App\Inventory\Domain\Stock\PhysicalStockLimitExceeded;
use App\Inventory\Domain\Stock\ReceiptKeyReused;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockMovementType;
use App\Inventory\Domain\Stock\VariantNotFound;
use App\Shared\Domain\Exception\NotFound;
use App\Tests\Unit\Inventory\InMemoryReservationRepository;
use App\Tests\Unit\Inventory\InMemoryStockItemRepository;
use App\Tests\Unit\Inventory\InMemoryTransaction;
use App\Tests\Unit\Inventory\InMemoryVariantCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiveStockHandlerTest extends TestCase
{
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';

    private InMemoryStockItemRepository $stock;
    private InMemoryVariantCatalog $catalog;
    private InMemoryTransaction $transaction;
    private ReceiveStockHandler $handler;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockItemRepository();
        $this->catalog = new InMemoryVariantCatalog();
        $this->catalog->add(self::A, self::B);
        $this->transaction = new InMemoryTransaction($this->stock, new InMemoryReservationRepository());
        $this->handler = new ReceiveStockHandler($this->stock, $this->catalog);
    }

    public function testTheFirstReceiptCreatesTheStockAndRecordsTheStaffMember(): void
    {
        $result = $this->receive(ReceiveStock::byStaff(self::A, 12, 'delivery-1', ' Dostawa ', 'sub-1', 'Wanda Magazyn'));

        self::assertFalse($result->repeated);
        $this->assertView(self::A, 12, 0, 12, 'in_stock', 1, $result->stock);
        self::assertSame([12, 0, 1], $this->state(self::A));
        self::assertCount(1, $this->stock->movements);
        $movement = $this->stock->movements[0];
        self::assertSame(StockMovementType::Receipt, $movement->type());
        self::assertSame([12, 12, 'Dostawa', 'delivery-1'], [$movement->physicalDelta(), $movement->physicalAfter(), $movement->note(), $movement->reference()]);
        self::assertSame([StockChangeSource::Staff, 'sub-1', 'Wanda Magazyn'], [$movement->origin()->source(), $movement->origin()->actorId(), $movement->origin()->actorName()]);
    }

    public function testAReceiptAddsToAStoredStock(): void
    {
        $this->stock->store(self::A, 3, 2, version: 4);

        $result = $this->receive(ReceiveStock::byStaff(self::A, 2, 'delivery-1', null, 'sub-1', 'Wanda Magazyn'));

        $this->assertView(self::A, 5, 2, 3, 'low_stock', 5, $result->stock);
        self::assertSame([[self::A]], $this->stock->locks);
    }

    public function testTheDemoDataReceivesWithoutAnActor(): void
    {
        $this->receive(ReceiveStock::bySeed(self::A, 30, 'seed:'.self::A));

        $origin = $this->stock->movements[0]->origin();
        self::assertSame([StockChangeSource::Seed, null, null], [$origin->source(), $origin->actorId(), $origin->actorName()]);
        self::assertNull($this->stock->movements[0]->note());
    }

    public function testTheSameKeyWithTheSameVariantQuantityAndNoteIsARepeatThatChangesNothing(): void
    {
        $this->receive(ReceiveStock::byStaff(self::A, 12, 'delivery-1', 'Dostawa', 'sub-1', 'Wanda Magazyn'));
        $this->stock->store(self::A, 12, 4, version: 2);

        $repeat = $this->receive(ReceiveStock::byStaff(self::A, 12, 'delivery-1', 'Dostawa ', 'sub-2', 'Ktoś Inny'));

        self::assertTrue($repeat->repeated);
        $this->assertView(self::A, 12, 4, 8, 'in_stock', 2, $repeat->stock);
        self::assertSame([12, 4, 2], $this->state(self::A));
        self::assertCount(1, $this->stock->movements);
    }

    /**
     * @return iterable<string, array{string, int, string|null}>
     */
    public static function otherContents(): iterable
    {
        yield 'another quantity' => [self::A, 13, 'Dostawa'];
        yield 'another note' => [self::A, 12, 'Inna dostawa'];
        yield 'no note' => [self::A, 12, null];
        yield 'another variant' => [self::B, 12, 'Dostawa'];
    }

    #[DataProvider('otherContents')]
    public function testTheSameKeyWithOtherContentIsReceiptKeyReusedAndChangesNothing(string $variantId, int $quantity, ?string $note): void
    {
        $this->receive(ReceiveStock::byStaff(self::A, 12, 'delivery-1', 'Dostawa', 'sub-1', 'Wanda Magazyn'));

        try {
            $this->receive(ReceiveStock::byStaff($variantId, $quantity, 'delivery-1', $note, 'sub-1', 'Wanda Magazyn'));
            self::fail('Expected ReceiptKeyReused.');
        } catch (ReceiptKeyReused) {
        }

        self::assertSame([12, 0, 1], $this->state(self::A));
        self::assertNull($this->stock->stored(self::B));
        self::assertCount(1, $this->stock->movements);
    }

    public function testAnUnknownVariantIsNotFoundAndNothingIsCreated(): void
    {
        $unknown = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a69';

        try {
            $this->receive(ReceiveStock::byStaff($unknown, 1, 'delivery-1', null, 'sub-1', 'Wanda Magazyn'));
            self::fail('Expected VariantNotFound.');
        } catch (VariantNotFound $notFound) {
            self::assertContains(NotFound::class, class_implements($notFound));
            self::assertSame('Wariant produktu nie istnieje.', $notFound->getMessage());
        }

        self::assertNull($this->stock->stored($unknown));
        self::assertSame([], $this->stock->locks);
    }

    public function testAnUpperCaseVariantIdNamesTheSameStock(): void
    {
        $this->stock->store(self::A, 1);

        $result = $this->receive(ReceiveStock::byStaff(strtoupper(self::A), 1, 'delivery-1', null, 'sub-1', 'Wanda Magazyn'));

        self::assertSame(self::A, $result->stock->variantId);
        self::assertSame([2, 0, 2], $this->state(self::A));
    }

    /**
     * @return iterable<string, array{ReceiveStock, class-string<\Throwable>}>
     */
    public static function invalidReceipts(): iterable
    {
        yield 'zero units' => [ReceiveStock::byStaff(self::A, 0, 'k', null, 'sub-1', 'Wanda'), InvalidStockQuantity::class];
        yield 'over 100 000 units' => [ReceiveStock::byStaff(self::A, 100_001, 'k', null, 'sub-1', 'Wanda'), InvalidStockQuantity::class];
        yield 'a malformed key' => [ReceiveStock::byStaff(self::A, 1, 'k k', null, 'sub-1', 'Wanda'), InvalidReceiptKey::class];
        yield 'a note over 500 characters' => [ReceiveStock::byStaff(self::A, 1, 'k', str_repeat('n', 501), 'sub-1', 'Wanda'), InvalidStockNote::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('invalidReceipts')]
    public function testAnInvalidReceiptIsRefusedAndCreatesNothing(ReceiveStock $command, string $expected): void
    {
        $this->expectException($expected);

        try {
            $this->receive($command);
        } finally {
            self::assertNull($this->stock->stored(self::A));
            self::assertSame([], $this->stock->movements);
        }
    }

    public function testAReceiptBeyondOneMillionIsRefusedAndChangesNothing(): void
    {
        $this->stock->store(self::A, 999_999, version: 3);

        try {
            $this->receive(ReceiveStock::byStaff(self::A, 2, 'k', null, 'sub-1', 'Wanda'));
            self::fail('Expected PhysicalStockLimitExceeded.');
        } catch (PhysicalStockLimitExceeded) {
        }

        self::assertSame([999_999, 0, 3], $this->state(self::A));
        self::assertSame([], $this->stock->movements);
    }

    private function receive(ReceiveStock $command): ReceiveStockResult
    {
        return $this->transaction->run(fn (): ReceiveStockResult => ($this->handler)($command));
    }

    private function assertView(string $variantId, int $physical, int $reserved, int $available, string $level, int $version, StockView $view): void
    {
        self::assertSame(
            [$variantId, $physical, $reserved, $available, $level, $version],
            [$view->variantId, $view->physical, $view->reserved, $view->available, $view->level, $view->version],
        );
        self::assertNotNull($view->updatedAt);
    }

    /**
     * @return array{int, int, int} physical, reserved, version
     */
    private function state(string $variantId): array
    {
        $item = $this->stock->stored($variantId);
        self::assertNotNull($item);

        return [$item->physical(), $item->reserved(), $item->version()];
    }
}
