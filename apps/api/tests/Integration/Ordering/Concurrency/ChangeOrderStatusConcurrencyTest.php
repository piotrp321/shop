<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderTransitionNotAllowed;
use App\Ordering\Domain\StaffTransition;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Inventory\CatalogFixture;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Changes of staff at the same moment on PostgreSQL, each participant a process of its own dispatching on
 * command.bus (ConcurrentCommands of the Inventory tests). The orders are placed and paid through the commands
 * beforehand and commit, so they are deleted after the test. A command of staff locks the order before it reaches
 * Inventory, so concurrent changes with the same version run one after another: one wins, the others see another
 * version, and Inventory never sees a reservation in a state the winner left (no ReservationStateConflict).
 */
final class ChangeOrderStatusConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;
    /** 1 000 net + 23 % VAT, the price of every variant of the test. */
    private const int GROSS = 1_230;

    private Connection $control;
    /** @var list<string> */
    private array $products = [];
    /** @var list<string> */
    private array $variants = [];
    /** @var list<string> */
    private array $carts = [];
    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
    }

    protected function tearDown(): void
    {
        if ([] !== $this->keys) {
            $orderIds = $this->control->fetchFirstColumn('SELECT id FROM ordering_order WHERE idempotency_key IN (?)', [$this->keys], [ArrayParameterType::STRING]);
            OrderingFixture::deleteOrders($this->control, array_values(array_filter($orderIds, is_string(...))));
        }
        CartFixture::delete($this->control, $this->carts, []);
        if ([] !== $this->variants) {
            $this->control->executeStatement('DELETE FROM pricing_variant_price WHERE variant_id IN (?)', [$this->variants], [ArrayParameterType::STRING]);
        }
        StockFixture::delete($this->control, $this->variants, []);
        CatalogFixture::delete($this->control, $this->products);
        $this->control->close();
        parent::tearDown();
    }

    public function testShipAndCancelWithTheSameVersionEndWithOneWinnerAndTheReservationOfTheWinner(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            [$orderId] = $this->paidOrders([[[$variant, 2]]]);

            $outcomes = ConcurrentCommands::run($this->control, [
                self::change($orderId, StaffTransition::Ship, 2),
                self::change($orderId, StaffTransition::Cancel, 2),
                self::change($orderId, StaffTransition::Ship, 2),
                self::change($orderId, StaffTransition::Cancel, 2),
            ]);

            $message = 'round '.$round.': '.self::messages($outcomes);
            self::assertSame([OrderModifiedConcurrently::class => 3, Outcome::SUCCESS => 1], self::kinds($outcomes), $message);
            $status = $this->control->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]);
            self::assertSame(3, $this->control->fetchOne('SELECT version FROM ordering_order WHERE id = ?', [$orderId]), $message);
            self::assertSame(3, OrderingFixture::count($this->control, 'SELECT COUNT(*) FROM ordering_order_status_change WHERE order_id = ?', [$orderId]), $message.': one entry of staff');
            $reservation = $this->control->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]);
            if ('shipped' === $status) {
                self::assertSame('committed', $reservation, $message);
                self::assertSame([8, 0], OrderingFixture::stock($this->control, $variant), $message.': the units left the shelf');
            } else {
                self::assertSame('cancelled', $status, $message);
                self::assertSame('released', $reservation, $message);
                self::assertSame([10, 0], OrderingFixture::stock($this->control, $variant), $message.': the units are back');
            }
        }
    }

    public function testTheExpiryAndAChangeOfStaffOfAnOverdueOrderDoNotDeadlock(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            [$orderId] = $this->placedOrders([[[$variant, 3]]]);
            // The deadline and the window for the webhooks after it have passed.
            $this->control->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '1 hour' WHERE id = ?", [$orderId]);

            $outcomes = ConcurrentCommands::run($this->control, [
                self::change($orderId, StaffTransition::Cancel, 1),
                new Envelope(new ExpireUnpaidOrder($orderId), [new ReceivedStamp('scheduled')]),
                self::change($orderId, StaffTransition::Ship, 1),
            ]);

            $message = 'round '.$round.': '.self::messages($outcomes);
            self::assertSame(Outcome::SUCCESS, $outcomes[1]->kind, $message);
            foreach ([$outcomes[0], $outcomes[2]] as $staff) {
                self::assertContains($staff->kind, [OrderTransitionNotAllowed::class, OrderModifiedConcurrently::class], $message);
            }
            self::assertSame(['cancelled', 'payment_timeout'], $this->control->fetchNumeric('SELECT status, cancellation_reason FROM ordering_order WHERE id = ?', [$orderId]), $message);
            self::assertSame('released', $this->control->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]), $message);
            self::assertSame([10, 0], OrderingFixture::stock($this->control, $variant), $message);
        }
    }

    public function testChangesOfTwoOrdersOfTheSameVariantsInOppositeOrderBothSucceedWithoutADeadlock(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $a = $this->variant(10);
            $b = $this->variant(10);
            [$first, $second] = $this->paidOrders([[[$a, 1], [$b, 2]], [[$b, 3], [$a, 4]]]);

            $outcomes = ConcurrentCommands::run($this->control, [
                self::change($first, StaffTransition::Ship, 2),
                self::change($second, StaffTransition::Cancel, 2),
            ]);

            $message = 'round '.$round.': '.self::messages($outcomes);
            self::assertSame([Outcome::SUCCESS => 2], self::kinds($outcomes), $message);
            self::assertSame([9, 0], OrderingFixture::stock($this->control, $a), $message.': the first order shipped 1, the second released 4');
            self::assertSame([8, 0], OrderingFixture::stock($this->control, $b), $message.': the first order shipped 2, the second released 3');
        }
    }

    private static function change(string $orderId, StaffTransition $transition, int $version): ChangeOrderStatus
    {
        return new ChangeOrderStatus($orderId, $transition, $version, StaffTransition::Cancel === $transition ? 'Klient zrezygnował' : null, 'kc-staff-1', 'anna');
    }

    /**
     * Orders placed at once (one process per order, they do not compete) and then paid as MarkOrderPaid pays them
     * (written by SQL: the result of the command, a non-backed enum, does not travel back from a participant).
     *
     * @param list<list<array{string, int}>> $orders the lines (variant, quantity) of each order
     *
     * @return list<string> the order ids, at version 2
     */
    private function paidOrders(array $orders): array
    {
        $orderIds = $this->placedOrders($orders);
        foreach ($orderIds as $orderId) {
            $this->control->executeStatement("UPDATE ordering_order SET status = 'paid', paid_at = now(), payment_id = gen_random_uuid(), version = version + 1 WHERE id = ? AND status = 'new'", [$orderId]);
            $this->control->executeStatement("INSERT INTO ordering_order_status_change (order_id, position, from_status, to_status, occurred_at, actor_type) VALUES (?, 1, 'new', 'paid', now(), 'system')", [$orderId]);
        }

        return $orderIds;
    }

    /**
     * @param list<list<array{string, int}>> $orders the lines (variant, quantity) of each order
     *
     * @return list<string> the order ids, new at version 1
     */
    private function placedOrders(array $orders): array
    {
        $commands = [];
        foreach ($orders as $lines) {
            $cart = CartFixture::cart($this->control, expiresAt: '+30 days', lines: $lines);
            $this->carts[] = $cart;
            $key = 'status-concurrency-'.Uuid::v7()->toRfc4122();
            $this->keys[] = $key;
            $gross = array_sum(array_map(static fn (array $line): int => $line[1] * self::GROSS, $lines));
            $commands[] = new PlaceOrder(CartReference::guest($cart), 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', $gross, $key);
        }
        $outcomes = ConcurrentCommands::run($this->control, $commands);
        self::assertSame([Outcome::SUCCESS => \count($orders)], self::kinds($outcomes), self::messages($outcomes));

        return array_map(static function (Outcome $outcome): string {
            $order = $outcome->result['order'] ?? null;
            $orderId = \is_array($order) ? ($order['orderId'] ?? null) : null;
            \assert(\is_string($orderId));

            return $orderId;
        }, $outcomes);
    }

    private function variant(int $stock): string
    {
        [$variant, $product] = OrderingFixture::variant($this->control, 1_000, $stock);
        $this->variants[] = $variant;
        $this->products[] = $product;

        return $variant;
    }

    /**
     * @param list<Outcome> $outcomes
     *
     * @return array<string, int> the number of outcomes of each kind, by kind
     */
    private static function kinds(array $outcomes): array
    {
        $kinds = array_count_values(array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes));
        ksort($kinds);

        return $kinds;
    }

    /**
     * @param list<Outcome> $outcomes
     */
    private static function messages(array $outcomes): string
    {
        return implode('; ', array_map(static fn (Outcome $outcome): string => $outcome->kind.(null === $outcome->message ? '' : ' '.$outcome->message), $outcomes));
    }
}
