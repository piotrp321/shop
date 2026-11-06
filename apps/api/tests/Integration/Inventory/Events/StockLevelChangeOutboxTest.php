<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Events;

use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\ReceiptKey;
use App\Inventory\Domain\Stock\StockChangeOrigin;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockLevelChanged;
use App\Inventory\Domain\Stock\StockNote;
use App\Inventory\Domain\Stock\VariantId;
use App\Inventory\Infrastructure\Persistence\Doctrine\DoctrineStockItemRepository;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;

/**
 * StockLevelChanged goes to the outbox (the real Doctrine transport of the `test_messaging` kernel) on the connection
 * of the stock write, in its transaction: committed with it, rolled back with it, also to a savepoint of a nested
 * dispatch.
 */
final class StockLevelChangeOutboxTest extends MessagingKernelTestCase
{
    private DoctrineStockItemRepository $stocks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stocks = new DoctrineStockItemRepository($this->connection(), $this->publisher());
    }

    public function testASavedLevelChangeLeavesOneEventInTheOutbox(): void
    {
        $id = StockFixture::stock($this->connection(), 0);

        $this->connection()->transactional(function () use ($id): void {
            $this->receive($id, 10);
        });

        self::assertSame([[$id, AvailabilityLevel::InStock, StockChangeSource::Staff]], $this->outboxEvents());
    }

    public function testASavedChangeWithinTheLevelLeavesNoEvent(): void
    {
        $id = StockFixture::stock($this->connection(), 10);

        $this->connection()->transactional(function () use ($id): void {
            $this->receive($id, 5);
            $item = $this->stocks->lockMany([VariantId::fromString($id)])[0];
            $item->reserve(2, ReservationKey::fromString('order-1'));
            $this->stocks->save($item);
        });

        self::assertSame([], $this->outboxEvents());
        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM inventory_stock_movement WHERE variant_id = ? AND type = ?', [$id, 'reservation']));
    }

    public function testARolledBackTransactionLeavesNeitherTheChangeNorItsEvent(): void
    {
        $id = StockFixture::stock($this->connection(), 0);

        $failure = null;
        try {
            $this->connection()->transactional(function () use ($id): void {
                $this->receive($id, 10);
                self::assertSame(1, $this->rows('outbox'), 'the event is written in the transaction');

                throw new \LogicException('Failure before commit.');
            });
        } catch (\LogicException $e) {
            $failure = $e;
        }

        self::assertSame('Failure before commit.', $failure->getMessage());
        self::assertSame(0, $this->rows('outbox'));
        self::assertSame(0, $this->connection()->fetchOne('SELECT physical FROM inventory_stock_item WHERE variant_id = ?', [$id]));
    }

    /**
     * A nested dispatch runs in a savepoint: rolling back to it removes its event, the outer transaction keeps its own.
     */
    public function testARollbackToASavepointRemovesOnlyTheEventsOfTheNestedWrite(): void
    {
        $outer = StockFixture::stock($this->connection(), 0);
        $nested = StockFixture::stock($this->connection(), 0);
        $connection = $this->connection();

        $connection->transactional(function () use ($connection, $outer, $nested): void {
            $this->receive($outer, 3);
            $connection->beginTransaction();
            $this->receive($nested, 10);
            self::assertSame(2, $this->rows('outbox'));
            $connection->rollBack();
        });

        self::assertSame([[$outer, AvailabilityLevel::LowStock, StockChangeSource::Staff]], $this->outboxEvents());
        self::assertSame(0, $connection->fetchOne('SELECT physical FROM inventory_stock_item WHERE variant_id = ?', [$nested]));
    }

    private function receive(string $variantId, int $quantity): void
    {
        $item = $this->stocks->lockOrCreate(VariantId::fromString($variantId));
        $item->receive($quantity, ReceiptKey::fromString('receipt-'.bin2hex(random_bytes(6))), StockNote::none(), StockChangeOrigin::staff('sub-1', 'Wanda Magazyn'));
        $this->stocks->save($item);
    }

    /**
     * @return list<array{string, AvailabilityLevel, StockChangeSource}> the events in the outbox, oldest first
     */
    private function outboxEvents(): array
    {
        $events = [];
        // The Doctrine transport hands out the oldest message not taken yet: read until there is none.
        while ([] !== $envelopes = [...$this->transport('outbox')->get()]) {
            foreach ($envelopes as $envelope) {
                $event = $envelope->getMessage();
                self::assertInstanceOf(StockLevelChanged::class, $event);
                $events[] = [$event->variantId, $event->level, $event->source];
            }
        }

        return $events;
    }
}
