<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use App\Kernel;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\ReadModel\SalesRebuild;
use App\Ordering\Domain\OrderId;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalSalesHours;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ordering:read-models:rebuild (its sales part) alongside payments, each in a process of its own with a kernel and a
 * connection of its own (ParallelProcesses): whatever the interleaving, the table ends as the payments alone would
 * have written it, so no order is lost or counted twice (spec ADMIN-FEATURES, „Wdrożenie”). The table of the payments
 * is derived here from the orders themselves (hour of paid_at in UTC, the low four bits of the last byte of the id).
 *
 * Two interleavings are forced: a payment that has added its order but not committed while the rebuild reaches its
 * hour (the rebuild must wait and count it), and a rebuild of the hour that has not committed while a payment adds to
 * it (the payment must wait and add itself after). The third lets many payments and repeated rebuilds race. The rows
 * commit, so the orders and the sales are deleted after the test.
 */
final class SalesRebuildConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 3;
    private const float WAIT_SECONDS = 10.0;
    private const int GROSS = 1_230;

    private Connection $control;
    /** @var list<string> */
    private array $orders = [];

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
        self::assertSame(0, OrderingFixture::count($this->control, 'SELECT COUNT(*) FROM ordering_order WHERE paid_at IS NOT NULL'), 'shop_test has no paid orders of its own');
        $this->control->executeStatement('DELETE FROM ordering_sales_hour');
    }

    protected function tearDown(): void
    {
        OrderingFixture::deleteOrders($this->control, $this->orders);
        $this->control->executeStatement('DELETE FROM ordering_sales_hour');
        $this->control->close();
        parent::tearDown();
    }

    public function testARebuildThatReachesAnUncommittedPaymentWaitsForItAndCountsIt(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$paidBefore, $paying] = $this->twoOrdersOfOneShard();
            $flags = self::flags();

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::payment($paying, holdUntilAnotherWaits: true, flag: $flags.'/paid'),
                    self::rebuild(afterFlag: $flags.'/paid'),
                ]);
            } finally {
                self::removeFlags($flags);
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame('paid, another waited', $outcomes[0], $message);
            self::assertStringStartsWith('sales: ', $outcomes[1], $message);
            self::assertSame($this->writtenByThePayments(), $this->rows(), $message);
            self::assertSame(2 * $round, $this->paidOrders(), $message);
            unset($paidBefore);
        }
    }

    public function testAPaymentThatReachesAnUncommittedRebuildWaitsForItAndAddsItself(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [, $paying] = $this->twoOrdersOfOneShard();
            $flags = self::flags();

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::payment($paying, afterFlag: $flags.'/rebuilt'),
                    self::rebuild(holdUntilAnotherWaits: true, flag: $flags.'/rebuilt'),
                ]);
            } finally {
                self::removeFlags($flags);
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame('paid', $outcomes[0], $message);
            self::assertSame('rebuilt, another waited', $outcomes[1], $message);
            self::assertSame($this->writtenByThePayments(), $this->rows(), $message);
        }
    }

    public function testManyPaymentsAndRepeatedRebuildsAtOnceLeaveTheTableOfThePayments(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $this->twoOrdersOfOneShard();
            $orders = [];
            for ($i = 0; $i < 30; ++$i) {
                $orders[] = $this->order();
            }

            $flags = self::flags();

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::payments(\array_slice($orders, 0, 15), $flags.'/first'),
                    self::payments(\array_slice($orders, 15), $flags.'/second'),
                    self::rebuild(untilFlags: [$flags.'/first', $flags.'/second']),
                ]);
            } finally {
                self::removeFlags($flags);
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame(['paid 15', 'paid 15'], \array_slice($outcomes, 0, 2), $message);
            self::assertMatchesRegularExpression('/^rebuilt \d+ times while the payments ran$/', $outcomes[2], $message);
            self::assertSame($this->writtenByThePayments(), $this->rows(), $message);
            // The order paid before each round and the 30 of the round.
            self::assertSame(31 * $round, $this->paidOrders(), $message);
        }
    }

    /**
     * An order paid (committed) in this hour with its row of sales, and a new order of the same shard: its payment
     * updates the row of the first, which the rebuild of the hour locks.
     *
     * @return array{string, string} the paid order and the new one
     */
    private function twoOrdersOfOneShard(): array
    {
        $paid = $this->order(paid: true);
        $paidAt = $this->control->fetchOne('SELECT paid_at FROM ordering_order WHERE id = ?', [$paid]);
        \assert(\is_string($paidAt));
        new DbalSalesHours($this->control)->add(OrderId::fromString($paid), new \DateTimeImmutable($paidAt), self::GROSS);
        $id = Uuid::v7()->toRfc4122();

        return [$paid, $this->order(id: substr($id, 0, 35).$paid[35])];
    }

    private function order(bool $paid = false, ?string $id = null): string
    {
        $orderId = OrderingFixture::order($this->control, paid: $paid, id: $id);
        $this->orders[] = $orderId;

        return $orderId;
    }

    /**
     * Pays the order through MarkOrderPaid on command.bus in a transaction of the participant, as the webhook does.
     * After $afterFlag appears, if given; with $holdUntilAnotherWaits the transaction stays open after the payment
     * until another backend waits for a lock (at most 10 s), then commits.
     *
     * @return \Closure(): (\Closure(): string)
     */
    private static function payment(string $orderId, bool $holdUntilAnotherWaits = false, ?string $flag = null, ?string $afterFlag = null): \Closure
    {
        return static function () use ($orderId, $holdUntilAnotherWaits, $flag, $afterFlag): \Closure {
            [$connection, $container] = self::participant();
            $bus = $container->get('command.bus');
            \assert($bus instanceof MessageBusInterface);

            return static function () use ($connection, $bus, $orderId, $holdUntilAnotherWaits, $flag, $afterFlag): string {
                if (null !== $afterFlag && !self::await(static fn (): bool => is_file($afterFlag))) {
                    return 'the flag did not appear';
                }
                $connection->beginTransaction();
                $bus->dispatch(new MarkOrderPaid($orderId, Uuid::v7()->toRfc4122(), self::GROSS, 'PLN', new \DateTimeImmutable()));
                if (null !== $flag) {
                    touch($flag);
                }
                $waited = $holdUntilAnotherWaits && self::await(static fn (): bool => self::anotherWaits($connection));
                $connection->commit();

                return $waited ? 'paid, another waited' : 'paid';
            };
        };
    }

    /**
     * Pays the orders one after another, each in its own transaction of command.bus, then leaves $flag.
     *
     * @param list<string> $orderIds
     *
     * @return \Closure(): (\Closure(): string)
     */
    private static function payments(array $orderIds, string $flag): \Closure
    {
        return static function () use ($orderIds, $flag): \Closure {
            [, $container] = self::participant();
            $bus = $container->get('command.bus');
            \assert($bus instanceof MessageBusInterface);

            return static function () use ($bus, $orderIds, $flag): string {
                try {
                    foreach ($orderIds as $orderId) {
                        $bus->dispatch(new MarkOrderPaid($orderId, Uuid::v7()->toRfc4122(), self::GROSS, 'PLN', new \DateTimeImmutable()));
                    }
                } finally {
                    touch($flag);
                }

                return 'paid '.\count($orderIds);
            };
        };
    }

    /**
     * Runs the sales rebuild: once, or again and again until all $untilFlags appear (at most 10 s). After $afterFlag
     * appears, if given; with $holdUntilAnotherWaits it runs inside a transaction of the participant, which stays open
     * after the rebuild (holding the locks of the rows) until another backend waits for a lock (at most 10 s), then
     * commits.
     *
     * @param list<string> $untilFlags
     *
     * @return \Closure(): (\Closure(): string)
     */
    private static function rebuild(bool $holdUntilAnotherWaits = false, ?string $flag = null, ?string $afterFlag = null, array $untilFlags = []): \Closure
    {
        return static function () use ($holdUntilAnotherWaits, $flag, $afterFlag, $untilFlags): \Closure {
            [$connection, $container] = self::participant();
            $rebuild = $container->get(SalesRebuild::class);
            \assert($rebuild instanceof SalesRebuild);

            return static function () use ($connection, $rebuild, $holdUntilAnotherWaits, $flag, $afterFlag, $untilFlags): string {
                if (null !== $afterFlag && !self::await(static fn (): bool => is_file($afterFlag))) {
                    return 'the flag did not appear';
                }
                if ($holdUntilAnotherWaits) {
                    $connection->beginTransaction();
                }
                $summary = $rebuild->run(static function (): void {});
                if ([] !== $untilFlags) {
                    $runs = 1;
                    $deadline = microtime(true) + self::WAIT_SECONDS;
                    while (\count(array_filter($untilFlags, is_file(...))) < \count($untilFlags) && microtime(true) < $deadline) {
                        $rebuild->run(static function (): void {});
                        ++$runs;
                    }

                    return \sprintf('rebuilt %d times while the payments ran', $runs);
                }
                if (null !== $flag) {
                    touch($flag);
                }
                if (!$holdUntilAnotherWaits) {
                    return $summary;
                }
                $waited = self::await(static fn (): bool => self::anotherWaits($connection));
                $connection->commit();

                return $waited ? 'rebuilt, another waited' : 'rebuilt';
            };
        };
    }

    /**
     * A kernel of the participant (its connection commits).
     *
     * @return array{Connection, ContainerInterface}
     */
    private static function participant(): array
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $connection = $container->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return [$connection, $container];
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
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                return false;
            }
            usleep(5_000);
        }

        return true;
    }

    private static function flags(): string
    {
        $directory = sys_get_temp_dir().'/sales-rebuild-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700));

        return $directory;
    }

    private static function removeFlags(string $directory): void
    {
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }

    private function paidOrders(): int
    {
        return OrderingFixture::count($this->control, 'SELECT CAST(SUM(order_count) AS int) FROM ordering_sales_hour');
    }

    /**
     * What the payments alone write: a row per hour (UTC) and shard of the paid orders, with their number and total.
     *
     * @return list<array{string, int, int, int}>
     */
    private function writtenByThePayments(): array
    {
        $rows = [];
        foreach ($this->control->fetchAllNumeric("SELECT id, to_char(paid_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:00'), total_gross FROM ordering_order WHERE paid_at IS NOT NULL") as [$id, $hour, $gross]) {
            \assert(\is_string($id) && \is_string($hour) && \is_int($gross));
            $key = $hour.' '.hexdec($id[35]);
            $rows[$key] ??= [$hour, (int) hexdec($id[35]), 0, 0];
            ++$rows[$key][2];
            $rows[$key][3] += $gross;
        }
        usort($rows, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $rows;
    }

    /**
     * @return list<array{string, int, int, int}>
     */
    private function rows(): array
    {
        /** @var list<array{string, int, int, int}> $rows */
        $rows = $this->control->fetchAllNumeric("SELECT to_char(hour_start AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:00'), shard, order_count, CAST(gross AS int) FROM ordering_sales_hour ORDER BY hour_start, shard");

        return $rows;
    }
}
