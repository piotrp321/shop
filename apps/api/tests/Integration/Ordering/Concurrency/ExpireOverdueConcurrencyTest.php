<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use App\Kernel;
use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\MarkOrderPaidResult;
use App\Ordering\Application\Command\PlaceOrder;
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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * ordering:expire-overdue at the same moment as the payment of the same overdue order (its webhook, executed in time)
 * or as the delayed ExpireUnpaidOrder of it, each in a process of its own on PostgreSQL (spec:
 * docs/features/async-reliability/spec.md, W5, criterion 5). The expiry locks the order first, so the two go one after
 * another: the result is one consistent state (paid with the reservation, or cancelled once with it released) and the
 * command never fails. The orders are placed beforehand and commit, so they are deleted after the test.
 */
final class ExpireOverdueConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;
    /** 1 000 net + 23 % VAT, the price of the variant of the test. */
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
            $orderIds = array_values(array_filter($orderIds, is_string(...)));
            if ([] !== $orderIds) {
                $this->control->executeStatement('DELETE FROM ordering_unpaid_order_claim WHERE order_id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
            }
            OrderingFixture::deleteOrders($this->control, $orderIds);
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

    public function testTheCommandAndAPaymentInTimeOfTheSameOrderEndInOneConsistentState(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            $orderId = $this->overdueOrder($variant);

            $outcomes = ParallelProcesses::run($this->control, [
                self::expireOverdue(),
                self::payInTime($orderId),
            ]);

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertStringStartsWith('exit 0:', $outcomes[0], $message);
            $status = $this->control->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]);
            $reservation = $this->control->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]);
            if ('paid' === $status) {
                // The payment committed first; the expiry waited for it and found nothing to do.
                self::assertSame(MarkOrderPaidResult::Paid->name, $outcomes[1], $message);
                // Read as a candidate before the payment committed, or not at all.
                self::assertMatchesRegularExpression('/(No overdue orders|Expired 0 overdue orders)\./', $outcomes[0], $message);
                self::assertSame('active', $reservation, $message);
                self::assertSame([10, 2], OrderingFixture::stock($this->control, $variant), $message);
            } else {
                // The expiry committed first; the payment sees the cancelled order (a refund) or loses on the version.
                self::assertSame('cancelled', $status, $message);
                self::assertStringContainsString('Expired 1 overdue order.', $outcomes[0], $message);
                self::assertContains($outcomes[1], [MarkOrderPaidResult::OrderCancelled->name, 'App\Ordering\Application\Command\OrderChangedConcurrently'], $message);
                self::assertSame('released', $reservation, $message);
                self::assertSame([10, 0], OrderingFixture::stock($this->control, $variant), $message);
            }
            self::assertSame(2, OrderingFixture::count($this->control, 'SELECT COUNT(*) FROM ordering_order_status_change WHERE order_id = ?', [$orderId]), $message.': the placement and one change of the status');
        }
    }

    /**
     * The payment has written the paid order and holds its row until the command waits for the lock of it: the command
     * then reads the order as the payment committed it and leaves it paid.
     */
    public function testTheCommandWaitsForAPaymentThatHoldsTheOrderAndLeavesItPaid(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            $orderId = $this->overdueOrder($variant);

            $flag = sys_get_temp_dir().'/expire-overdue-'.bin2hex(random_bytes(8));

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::expireOverdue(afterFlag: $flag),
                    self::payInTime($orderId, holdUntilAnotherWaits: true, flag: $flag),
                ]);
            } finally {
                if (is_file($flag)) {
                    unlink($flag);
                }
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame('Paid, another waited', $outcomes[1], $message);
            self::assertSame('exit 0: [OK] Expired 0 overdue orders.', $outcomes[0], $message);
            self::assertSame('paid', $this->control->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]), $message);
            self::assertSame('active', $this->control->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]), $message);
            self::assertSame([10, 2], OrderingFixture::stock($this->control, $variant), $message);
        }
    }

    public function testTheCommandAndTheDelayedMessageOfTheSameOrderCancelItOnce(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            $orderId = $this->overdueOrder($variant);

            $outcomes = ParallelProcesses::run($this->control, [
                self::expireOverdue(),
                self::deliverExpiry($orderId),
                self::expireOverdue(),
            ]);

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertStringStartsWith('exit 0:', $outcomes[0], $message);
            self::assertSame('handled', $outcomes[1], $message);
            self::assertStringStartsWith('exit 0:', $outcomes[2], $message);
            self::assertSame(['cancelled', 'payment_timeout'], $this->control->fetchNumeric('SELECT status, cancellation_reason FROM ordering_order WHERE id = ?', [$orderId]), $message);
            self::assertSame('released', $this->control->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]), $message);
            self::assertSame([10, 0], OrderingFixture::stock($this->control, $variant), $message.': released once');
            self::assertSame(2, OrderingFixture::count($this->control, 'SELECT COUNT(*) FROM ordering_order_status_change WHERE order_id = ?', [$orderId]), $message.': the placement and one cancellation');
            self::assertSame(2, OrderingFixture::count($this->control, 'SELECT version FROM ordering_order WHERE id = ?', [$orderId]), $message);
        }
    }

    /**
     * @return \Closure(): (\Closure(): string) the command in a kernel of its own: "exit <code>: <output>"
     */
    private static function expireOverdue(?string $afterFlag = null): \Closure
    {
        return static function () use ($afterFlag): \Closure {
            $kernel = new Kernel('test', false);
            $kernel->boot();
            $tester = new CommandTester((new Application($kernel))->find('ordering:expire-overdue'));

            return static function () use ($tester, $afterFlag): string {
                if (null !== $afterFlag && !self::await(static fn (): bool => is_file($afterFlag))) {
                    return 'the flag did not appear';
                }
                $code = $tester->execute([]);

                return \sprintf('exit %d: %s', $code, preg_replace('/\s+/', ' ', trim($tester->getDisplay())));
            };
        };
    }

    /**
     * @return \Closure(): (\Closure(): string) the webhook of a payment executed before the deadline, as Payment
     *                                          dispatches it: the name of the result, or the class of the failure
     */
    private static function payInTime(string $orderId, bool $holdUntilAnotherWaits = false, ?string $flag = null): \Closure
    {
        return static function () use ($orderId, $holdUntilAnotherWaits, $flag): \Closure {
            [$connection, $bus] = self::participant();

            return static function () use ($connection, $bus, $orderId, $holdUntilAnotherWaits, $flag): string {
                $executedAt = new \DateTimeImmutable('-61 minutes');
                // An outer transaction keeps the row of the paid order locked after the command of the bus is done.
                $connection->beginTransaction();
                try {
                    $result = $bus->dispatch(new MarkOrderPaid($orderId, Uuid::v7()->toRfc4122(), self::GROSS * 2, 'PLN', $executedAt))->last(HandledStamp::class)?->getResult();
                } catch (HandlerFailedException $failed) {
                    $connection->rollBack();

                    return array_values($failed->getWrappedExceptions())[0]::class;
                }
                \assert($result instanceof MarkOrderPaidResult);
                if (null !== $flag) {
                    touch($flag);
                }
                $waited = $holdUntilAnotherWaits && self::await(static fn (): bool => self::anotherWaits($connection));
                $connection->commit();

                return $result->name.($waited ? ', another waited' : '');
            };
        };
    }

    /**
     * Whether a backend of shop_test other than this one waits for a lock on a row or a transaction (not the advisory
     * lock of the start).
     */
    private static function anotherWaits(Connection $connection): bool
    {
        return true === $connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE NOT granted AND locktype <> 'advisory' AND pid <> pg_backend_pid())",
        );
    }

    /**
     * @param \Closure(): bool $condition
     */
    private static function await(\Closure $condition): bool
    {
        $deadline = microtime(true) + 10.0;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                return false;
            }
            usleep(5000);
        }

        return true;
    }

    /**
     * @return \Closure(): (\Closure(): string) the delayed message, as the worker hands it to the bus
     */
    private static function deliverExpiry(string $orderId): \Closure
    {
        return static function () use ($orderId): \Closure {
            [, $bus] = self::participant();

            return static function () use ($bus, $orderId): string {
                $bus->dispatch(new Envelope(new ExpireUnpaidOrder($orderId), [new ReceivedStamp('scheduled')]));

                return 'handled';
            };
        };
    }

    /**
     * @return array{Connection, MessageBusInterface} of a kernel booted in the process of the participant
     */
    private static function participant(): array
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $connection = $container->get('doctrine.dbal.default_connection');
        $bus = $container->get('command.bus');
        \assert($connection instanceof Connection && $bus instanceof MessageBusInterface);

        return [$connection, $bus];
    }

    /**
     * A new order of 2 units of the variant, placed (committed) and moved into the past by SQL: placed 90 minutes ago,
     * its deadline an hour ago, past the window of the webhooks and the grace.
     */
    private function overdueOrder(string $variant): string
    {
        $cart = CartFixture::cart($this->control, expiresAt: '+30 days', lines: [[$variant, 2]]);
        $this->carts[] = $cart;
        $key = 'expire-overdue-concurrency-'.Uuid::v7()->toRfc4122();
        $this->keys[] = $key;
        $outcomes = ConcurrentCommands::run($this->control, [
            new PlaceOrder(CartReference::guest($cart), 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', OrderingFixture::freeDeliveryMethod($this->control), 2 * self::GROSS, OrderingFixture::expectedOfCart($this->control, $cart), $key),
        ]);
        self::assertSame(Outcome::SUCCESS, $outcomes[0]->kind, (string) $outcomes[0]->message);
        $order = $outcomes[0]->result['order'] ?? null;
        $orderId = \is_array($order) ? ($order['orderId'] ?? null) : null;
        \assert(\is_string($orderId));
        $this->control->executeStatement("UPDATE ordering_order SET placed_at = now() - interval '90 minutes', payment_deadline = now() - interval '60 minutes' WHERE id = ?", [$orderId]);

        return $orderId;
    }

    private function variant(int $stock): string
    {
        [$variant, $product] = OrderingFixture::variant($this->control, 1_000, $stock);
        $this->variants[] = $variant;
        $this->products[] = $product;

        return $variant;
    }
}
