<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Concurrency;

use App\Inventory\Application\Command\CorrectStock;
use App\Inventory\Application\Command\InsufficientStock;
use App\Inventory\Application\Command\ReceiveStock;
use App\Inventory\Application\Command\ReserveStock;
use App\Inventory\Application\Command\ReserveStockLine;
use App\Inventory\Domain\Stock\CorrectionBelowReserved;
use App\Inventory\Domain\Stock\ReceiptKeyReused;
use App\Inventory\Domain\Stock\StockModifiedConcurrently;
use App\Tests\Integration\Inventory\CatalogFixture;
use App\Tests\Integration\Inventory\IndependentConnections;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Scenarios C4 and C6 of the Inventory specification ("Testy – Współbieżność") on PostgreSQL: every participant is a
 * process of its own dispatching on command.bus (see ConcurrentCommands), all start at once. Each scenario runs 5
 * rounds on fresh Catalog products; after every round reserved is the sum of the lines of the active reservations,
 * physical is the sum of the physical deltas of the history, and nothing available is negative. The rows commit, so
 * they are deleted after the test.
 */
final class StockChangeConcurrencyTest extends KernelTestCase
{
    use IndependentConnections;

    private const int ROUNDS = 5;

    private Connection $control;

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $this->control = $this->independentConnection();
    }

    /**
     * Odd rounds receive into a product without stock (all participants race to create its row), even rounds into a
     * stored stock.
     */
    public function testC4TheSameReceiptTenTimesAtOnceAddsItsQuantityOnce(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $product = $this->catalogProduct();
            $initial = 0 === $round % 2 ? 7 : 0;
            if ($initial > 0) {
                $this->stock($product, $initial);
            }
            $key = $this->receiptKey('c4');
            $commands = array_fill(0, 10, ReceiveStock::byStaff($product, 5, $key, 'Dostawa', 'sub-1', 'Wanda Magazyn'));

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'C4 round '.$round;
            self::assertSame([Outcome::SUCCESS => 10], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertSame([0 => 1, 1 => 9], self::repeats($outcomes), $message.': one receipt, nine repeats');
            foreach ($outcomes as $outcome) {
                $stock = $outcome->result['stock'] ?? null;
                self::assertIsArray($stock, $message);
                self::assertSame([$product, $initial + 5], [$stock['productId'], $stock['physical']], $message);
            }
            self::assertSame(['physical' => $initial + 5, 'reserved' => 0, 'version' => $initial > 0 ? 2 : 1], $this->stockRow($product), $message);
            self::assertSame(1, $this->control->fetchOne("SELECT COUNT(*) FROM inventory_stock_movement WHERE type = 'receipt' AND reference = ?", [$key]), $message);
            $this->assertInvariant([$product], $message);
        }
    }

    /**
     * The same key sent for two products at once: one product gets the receipt, every request of the other is
     * refused as a reused key (by the history once the receipt committed, or by the unique index when both wrote at
     * the same time), never with a database error.
     */
    public function testC4TheSameReceiptKeyForTwoProductsAtOnceIsReceivedIntoOneOfThem(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $a = $this->catalogProduct();
            $b = $this->catalogProduct();
            $key = $this->receiptKey('c4b');
            $commands = [];
            for ($i = 0; $i < 10; ++$i) {
                $commands[] = ReceiveStock::byStaff(0 === $i % 2 ? $a : $b, 5, $key, null, 'sub-1', 'Wanda Magazyn');
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'C4 two products round '.$round;
            self::assertSame([ReceiptKeyReused::class => 5, Outcome::SUCCESS => 5], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            $winner = $this->control->fetchOne("SELECT product_id FROM inventory_stock_movement WHERE type = 'receipt' AND reference = ?", [$key]);
            self::assertContains($winner, [$a, $b], $message);
            $loser = $a === $winner ? $b : $a;
            foreach ($commands as $index => $command) {
                self::assertSame($winner === $command->productId ? Outcome::SUCCESS : ReceiptKeyReused::class, $outcomes[$index]->kind, $message);
            }
            self::assertSame(['physical' => 5, 'reserved' => 0, 'version' => 1], $this->stockRow((string) $winner), $message);
            self::assertFalse($this->control->fetchOne('SELECT 1 FROM inventory_stock_item WHERE product_id = ?', [$loser]), $message);
            $this->assertInvariant([(string) $winner], $message);
        }
    }

    /**
     * Corrections down, based on the version the staff member saw, at the same time as reservations of the product:
     * at most one correction is saved, never below what is reserved at that moment, and every state in the history
     * keeps physical ≥ reserved ≥ 0.
     */
    public function testC6CorrectionsDownAtTheSameTimeAsReservationsNeverGoBelowTheReservedStock(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $product = $this->catalogProduct();
            $this->stock($product, 10);
            $base = $this->reservationKey('c6-base');
            self::assertSame([Outcome::SUCCESS => 1], self::kinds(ConcurrentCommands::run($this->control, [new ReserveStock($base, [new ReserveStockLine($product, 4)])])));
            $version = $this->stockRow($product)['version'];
            $commands = [];
            foreach ([6, 5, 3, 2] as $counted) {
                $commands[] = CorrectStock::byStaff($product, $counted, $version, 'inventory_count', null, 'sub-1', 'Wanda Magazyn');
            }
            for ($i = 0; $i < 6; ++$i) {
                $commands[] = new ReserveStock($this->reservationKey('c6'), [new ReserveStockLine($product, 1)]);
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'C6 round '.$round;
            $corrections = \array_slice($outcomes, 0, 4);
            $reservations = \array_slice($outcomes, 4);
            foreach ($corrections as $index => $outcome) {
                $allowed = $index < 2
                    ? [Outcome::SUCCESS, StockModifiedConcurrently::class, CorrectionBelowReserved::class]
                    // Counting less than the 4 units reserved before the round is never saved.
                    : [StockModifiedConcurrently::class, CorrectionBelowReserved::class];
                self::assertContains($outcome->kind, $allowed, $message.': '.$outcome->message);
            }
            foreach ($reservations as $outcome) {
                self::assertContains($outcome->kind, [Outcome::SUCCESS, InsufficientStock::class], $message.': '.$outcome->message);
            }
            self::assertLessThanOrEqual(1, self::kinds($corrections)[Outcome::SUCCESS] ?? 0, $message);

            $row = $this->stockRow($product);
            self::assertSame(4 + (self::kinds($reservations)[Outcome::SUCCESS] ?? 0), $row['reserved'], $message);
            self::assertSame(self::kinds($corrections)[Outcome::SUCCESS] ?? 0, $this->movementCount($product, 'correction'), $message);
            $this->assertEveryStateInTheHistoryKeepsTheInvariant($product, $message);
            $this->assertInvariant([$product], $message);
        }
    }

    /**
     * @param list<string> $productIds
     */
    private function assertInvariant(array $productIds, string $message): void
    {
        foreach ($productIds as $id) {
            $row = $this->stockRow($id);
            $activeLines = $this->control->fetchOne(
                "SELECT COALESCE(SUM(l.quantity), 0) FROM inventory_reservation_line l JOIN inventory_reservation r ON r.reservation_key = l.reservation_key WHERE l.product_id = ? AND r.status = 'active'",
                [$id],
            );
            $physicalDeltas = $this->control->fetchOne('SELECT COALESCE(SUM(physical_delta), 0) FROM inventory_stock_movement WHERE product_id = ?', [$id]);

            self::assertSame($activeLines, $row['reserved'], $message.': reserved = the lines of the active reservations');
            self::assertSame($physicalDeltas, $row['physical'], $message.': physical = the physical deltas of the history');
            self::assertGreaterThanOrEqual(0, $row['physical'] - $row['reserved'], $message.': available ≥ 0');
        }
    }

    private function assertEveryStateInTheHistoryKeepsTheInvariant(string $productId, string $message): void
    {
        $broken = $this->control->fetchOne(
            'SELECT COUNT(*) FROM inventory_stock_movement WHERE product_id = ? AND NOT (reserved_after >= 0 AND physical_after >= reserved_after)',
            [$productId],
        );

        self::assertSame(0, $broken, $message.': physical ≥ reserved ≥ 0 after every operation');
    }

    /**
     * @param list<Outcome> $outcomes
     *
     * @return array<string, int> outcome kind => count, by kind
     */
    private static function kinds(array $outcomes): array
    {
        $kinds = array_count_values(array_map(static fn (Outcome $o): string => $o->kind, $outcomes));
        ksort($kinds);

        return $kinds;
    }

    /**
     * @param list<Outcome> $outcomes
     *
     * @return array<int, int> repeated (0 or 1) => count
     */
    private static function repeats(array $outcomes): array
    {
        $repeats = array_count_values(array_map(static fn (Outcome $o): int => match ($o->result['repeated'] ?? null) {
            true => 1,
            false => 0,
            default => -1,
        }, $outcomes));
        ksort($repeats);

        return $repeats;
    }

    /**
     * @param list<Outcome> $outcomes
     */
    private static function messages(array $outcomes): string
    {
        return implode(' | ', array_filter(array_map(static fn (Outcome $o): ?string => $o->message, $outcomes)));
    }

    private function catalogProduct(): string
    {
        $id = CatalogFixture::product($this->control);
        $this->committedCatalogProductIds[] = $id;
        $this->committedProductIds[] = $id;

        return $id;
    }

    private function stock(string $productId, int $physical): void
    {
        StockFixture::stock($this->control, $physical, productId: $productId);
    }

    private function receiptKey(string $scenario): string
    {
        return $scenario.':'.Uuid::v7()->toRfc4122();
    }

    private function reservationKey(string $scenario): string
    {
        $key = $scenario.':'.Uuid::v7()->toRfc4122();
        $this->committedReservationKeys[] = $key;

        return $key;
    }

    /**
     * @return array{physical: int, reserved: int, version: int}
     */
    private function stockRow(string $productId): array
    {
        $row = $this->control->fetchAssociative('SELECT physical, reserved, version FROM inventory_stock_item WHERE product_id = ?', [$productId]);
        \assert(\is_array($row) && \is_int($row['physical']) && \is_int($row['reserved']) && \is_int($row['version']));

        return ['physical' => $row['physical'], 'reserved' => $row['reserved'], 'version' => $row['version']];
    }

    private function movementCount(string $productId, string $type): int
    {
        $count = $this->control->fetchOne('SELECT COUNT(*) FROM inventory_stock_movement WHERE product_id = ? AND type = ?', [$productId, $type]);
        \assert(\is_int($count));

        return $count;
    }
}
