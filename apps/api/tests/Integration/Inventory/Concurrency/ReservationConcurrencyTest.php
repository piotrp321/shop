<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Concurrency;

use App\Inventory\Application\Command\CommitReservation;
use App\Inventory\Application\Command\InsufficientStock;
use App\Inventory\Application\Command\ReleaseReservation;
use App\Inventory\Application\Command\ReserveStock;
use App\Inventory\Application\Command\ReserveStockLine;
use App\Inventory\Domain\Reservation\ReservationStateConflict;
use App\Tests\Integration\Inventory\IndependentConnections;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Scenarios C1, C2, C3 and C5 of the Inventory specification ("Testy – Współbieżność") on PostgreSQL: every
 * participant is a process of its own dispatching on command.bus (see ConcurrentCommands), all start at once.
 * Each scenario runs 5 rounds on fresh products; after every round reserved is the sum of the lines of the active
 * reservations, physical is the sum of the physical deltas of the history, and nothing available is negative.
 * The rows commit, so they are deleted after the test.
 */
final class ReservationConcurrencyTest extends KernelTestCase
{
    use IndependentConnections;

    private const int ROUNDS = 5;

    private Connection $control;

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $this->control = $this->independentConnection();
    }

    public function testC1TwentyReservationsOfOneUnitOfSevenGiveExactlySevenSuccesses(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $product = $this->stock(7);
            $commands = [];
            for ($i = 0; $i < 20; ++$i) {
                $commands[] = new ReserveStock($this->key('c1', $i), [new ReserveStockLine($product, 1)]);
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'C1 round '.$round;
            self::assertSame([InsufficientStock::class => 13, Outcome::SUCCESS => 7], self::kinds($outcomes), $message);
            self::assertSame(['physical' => 7, 'reserved' => 7], $this->stockRow($product), $message);
            self::assertSame(['reservation' => 7], $this->movementCounts($product), $message);
            $this->assertInvariant([$product], $message);
        }
    }

    public function testC2ReservationsOfTwoProductsInBothOrdersNeverDeadlock(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $a = $this->stock(5);
            $b = $this->stock(3);
            $commands = [];
            for ($i = 0; $i < 10; ++$i) {
                $lines = [new ReserveStockLine($a, 1), new ReserveStockLine($b, 1)];
                $commands[] = new ReserveStock($this->key('c2', $i), 0 === $i % 2 ? $lines : array_reverse($lines));
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'C2 round '.$round;
            self::assertSame([InsufficientStock::class => 7, Outcome::SUCCESS => 3], self::kinds($outcomes), $message);
            self::assertSame(['physical' => 5, 'reserved' => 3], $this->stockRow($a), $message);
            self::assertSame(['physical' => 3, 'reserved' => 3], $this->stockRow($b), $message);
            self::assertSame(['reservation' => 3], $this->movementCounts($a), $message);
            self::assertSame(['reservation' => 3], $this->movementCounts($b), $message);
            $this->assertInvariant([$a, $b], $message);
        }
    }

    public function testC3TheSameReservationTenTimesAtOnceReservesOnce(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $a = $this->stock(10);
            $b = $this->stock(10);
            $key = $this->key('c3', 0);
            $commands = array_fill(0, 10, new ReserveStock($key, [new ReserveStockLine($a, 2), new ReserveStockLine($b, 1)]));

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'C3 round '.$round;
            self::assertSame([Outcome::SUCCESS => 10], self::kinds($outcomes), $message);
            $results = array_map(static fn (Outcome $o): ?array => $o->result, $outcomes);
            self::assertCount(1, array_unique(array_map(static fn (?array $r): string => json_encode($r, \JSON_THROW_ON_ERROR), $results)), $message);
            $lines = [['productId' => $a, 'quantity' => 2], ['productId' => $b, 'quantity' => 1]];
            usort($lines, static fn (array $x, array $y): int => strcmp($x['productId'], $y['productId']));
            self::assertSame(['key' => $key, 'status' => 'active', 'lines' => $lines], $results[0], $message);
            self::assertSame(1, $this->control->fetchOne('SELECT COUNT(*) FROM inventory_reservation WHERE reservation_key = ?', [$key]), $message);
            self::assertSame(2, $this->control->fetchOne('SELECT COUNT(*) FROM inventory_reservation_line WHERE reservation_key = ?', [$key]), $message);
            self::assertSame(['physical' => 10, 'reserved' => 2], $this->stockRow($a), $message);
            self::assertSame(['physical' => 10, 'reserved' => 1], $this->stockRow($b), $message);
            self::assertSame(['reservation' => 1], $this->movementCounts($a), $message);
            self::assertSame(['reservation' => 1], $this->movementCounts($b), $message);
            $this->assertInvariant([$a, $b], $message);
        }
    }

    public function testC5ReleasesAndCommitsOfTheSameReservationAtOnceMakeOneTransition(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $a = $this->stock(5);
            $b = $this->stock(3);
            $key = $this->key('c5', 0);
            $reserved = ConcurrentCommands::run($this->control, [new ReserveStock($key, [new ReserveStockLine($a, 2), new ReserveStockLine($b, 1)])]);
            self::assertSame([Outcome::SUCCESS => 1], self::kinds($reserved));
            $commands = [];
            for ($i = 0; $i < 10; ++$i) {
                $commands[] = 0 === $i % 2 ? new ReleaseReservation($key) : new CommitReservation($key);
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'C5 round '.$round;
            $status = $this->control->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$key]);
            self::assertContains($status, ['released', 'committed'], $message);
            $winner = 'released' === $status ? ReleaseReservation::class : CommitReservation::class;
            foreach ($commands as $index => $command) {
                if ($winner === $command::class) {
                    self::assertSame(Outcome::SUCCESS, $outcomes[$index]->kind, $message.': '.$outcomes[$index]->message);
                    self::assertSame($status, $outcomes[$index]->result['status'] ?? null, $message);
                } else {
                    self::assertSame(ReservationStateConflict::class, $outcomes[$index]->kind, $message.': '.$outcomes[$index]->message);
                }
            }
            if ('released' === $status) {
                self::assertSame(['physical' => 5, 'reserved' => 0], $this->stockRow($a), $message);
                self::assertSame(['physical' => 3, 'reserved' => 0], $this->stockRow($b), $message);
                self::assertSame(['release' => 1, 'reservation' => 1], $this->movementCounts($a), $message);
                self::assertSame(['release' => 1, 'reservation' => 1], $this->movementCounts($b), $message);
            } else {
                self::assertSame(['physical' => 3, 'reserved' => 0], $this->stockRow($a), $message);
                self::assertSame(['physical' => 2, 'reserved' => 0], $this->stockRow($b), $message);
                self::assertSame(['commit' => 1, 'reservation' => 1], $this->movementCounts($a), $message);
                self::assertSame(['commit' => 1, 'reservation' => 1], $this->movementCounts($b), $message);
            }
            $this->assertInvariant([$a, $b], $message);
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

    private function stock(int $physical): string
    {
        $id = StockFixture::stock($this->control, $physical);
        $this->committedProductIds[] = $id;

        return $id;
    }

    private function key(string $scenario, int $index): string
    {
        $key = \sprintf('%s:%s:%d', $scenario, Uuid::v7()->toRfc4122(), $index);
        $this->committedReservationKeys[] = $key;

        return $key;
    }

    /**
     * @return array{physical: int, reserved: int}
     */
    private function stockRow(string $productId): array
    {
        $row = $this->control->fetchAssociative('SELECT physical, reserved FROM inventory_stock_item WHERE product_id = ?', [$productId]);
        \assert(\is_array($row) && \is_int($row['physical']) && \is_int($row['reserved']));

        return ['physical' => $row['physical'], 'reserved' => $row['reserved']];
    }

    /**
     * @return array<string, int> movement type => count, without the fixture's receipt
     */
    private function movementCounts(string $productId): array
    {
        $counts = [];
        foreach ($this->control->fetchAllAssociative("SELECT type, COUNT(*) AS count FROM inventory_stock_movement WHERE product_id = ? AND type <> 'receipt' GROUP BY type ORDER BY type", [$productId]) as $row) {
            \assert(\is_string($row['type']) && \is_int($row['count']));
            $counts[$row['type']] = $row['count'];
        }

        return $counts;
    }
}
