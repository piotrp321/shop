<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use App\Kernel;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\ReadModel\CustomerRebuild;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\OrderId;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalCustomerSummaries;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The customer summaries written concurrently, each participant in a process of its own with a kernel and a
 * connection of its own (ParallelProcesses): whatever the interleaving, a summary is what its orders say, so no order
 * is lost or counted twice and the contact is the one of the newest order (spec ADMIN-FEATURES, section 5).
 *
 * Two placements of one customer, as PlaceOrder writes them (the order and its addition in one transaction): the newer
 * one adds itself first and holds its row until the older one waits for it, so the older one commits last and must
 * keep the contact of the newer one; and the other way round.
 *
 * ordering:read-models:rebuild (its customer part) alongside placements and payments (MarkOrderPaid on command.bus,
 * as the webhook pays): a placement or a payment that has added itself but not committed while the rebuild reaches
 * its customer (the rebuild must wait and count it), a rebuild of the batch that has not committed while a placement
 * or a payment adds to it (which must wait and add itself after), and many placements (new customers among them) and
 * payments in two processes while the rebuild runs again and again. The summaries end as the orders say. The rows
 * commit, so the orders and the summaries of the test are deleted after it.
 */
final class CustomerSummariesConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 3;
    private const float WAIT_SECONDS = 10.0;
    private const int GROSS = 1_230;

    private Connection $control;
    /** @var list<string> */
    private array $orders = [];
    /** @var list<string> */
    private array $customers = [];

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
        self::assertSame(0, OrderingFixture::count($this->control, "SELECT COUNT(*) FROM ordering_order WHERE owner_type = 'customer'"), 'shop_test has no orders of customers of its own');
        $this->control->executeStatement('DELETE FROM ordering_customer');
    }

    protected function tearDown(): void
    {
        OrderingFixture::deleteOrders($this->control, $this->orders);
        if ([] !== $this->customers) {
            $this->control->executeStatement('DELETE FROM ordering_customer WHERE customer_id IN (?)', [$this->customers], [ArrayParameterType::STRING]);
        }
        $this->control->close();
        parent::tearDown();
    }

    public function testAnOlderPlacementCommittedAfterANewerOneKeepsTheContactOfTheNewer(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customer();
            $flags = self::flags();

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::placement($customer, $this->orderId(), 'newer@example.com', '2031-05-04 12:00:01+00:00', holdUntilAnotherWaits: true, flag: $flags.'/newer'),
                    self::placement($customer, $this->orderId(), 'older@example.com', '2031-05-04 12:00:00+00:00', afterFlag: $flags.'/newer'),
                ]);
            } finally {
                self::removeFlags($flags);
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame(['placed, another waited', 'placed'], $outcomes, $message);
            self::assertSame(['newer@example.com', 2, '2031-05-04 12:00:00', '2031-05-04 12:00:01'], $this->summary($customer), $message);
            self::assertSame($this->fromTheOrders($customer), $this->summary($customer), $message);
        }
    }

    public function testANewerPlacementCommittedAfterAnOlderOneTakesItsContact(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customer();
            $flags = self::flags();

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::placement($customer, $this->orderId(), 'older@example.com', '2031-05-04 12:00:00+00:00', holdUntilAnotherWaits: true, flag: $flags.'/older'),
                    self::placement($customer, $this->orderId(), 'newer@example.com', '2031-05-04 12:00:01+00:00', afterFlag: $flags.'/older'),
                ]);
            } finally {
                self::removeFlags($flags);
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame(['placed, another waited', 'placed'], $outcomes, $message);
            self::assertSame(['newer@example.com', 2, '2031-05-04 12:00:00', '2031-05-04 12:00:01'], $this->summary($customer), $message);
        }
    }

    public function testARebuildThatReachesAnUncommittedPlacementWaitsForItAndCountsIt(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customerWithAnOrder();
            $flags = self::flags();

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::placement($customer, $this->orderId(), 'newer@example.com', '2031-05-04 13:00:00+00:00', holdUntilAnotherWaits: true, flag: $flags.'/placed'),
                    self::rebuild(afterFlag: $flags.'/placed'),
                ]);
            } finally {
                self::removeFlags($flags);
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame('placed, another waited', $outcomes[0], $message);
            self::assertStringStartsWith('customers: ', $outcomes[1], $message);
            self::assertSame($this->allFromTheOrders(), $this->allSummaries(), $message);
        }
    }

    public function testAPlacementThatReachesAnUncommittedRebuildWaitsForItAndAddsItself(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customerWithAnOrder();
            $flags = self::flags();

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::placement($customer, $this->orderId(), 'newer@example.com', '2031-05-04 13:00:00+00:00', afterFlag: $flags.'/rebuilt'),
                    self::rebuild(holdUntilAnotherWaits: true, flag: $flags.'/rebuilt'),
                ]);
            } finally {
                self::removeFlags($flags);
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame(['placed', 'rebuilt, another waited'], $outcomes, $message);
            self::assertSame($this->allFromTheOrders(), $this->allSummaries(), $message);
        }
    }

    public function testARebuildThatReachesAnUncommittedPaymentWaitsForItAndCountsIt(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customerWithAnOrder();
            $unpaid = $this->order($customer, '2031-05-04 13:00:00+00:00', added: true);
            $flags = self::flags();

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::payment($unpaid, holdUntilAnotherWaits: true, flag: $flags.'/paid'),
                    self::rebuild(afterFlag: $flags.'/paid'),
                ]);
            } finally {
                self::removeFlags($flags);
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame('paid, another waited', $outcomes[0], $message);
            self::assertStringStartsWith('customers: ', $outcomes[1], $message);
            self::assertSame($this->allFromTheOrders(), $this->allSummaries(), $message);
            self::assertSame(1, $this->summaryOf($customer)['paid_count'], $message);
        }
    }

    public function testAPaymentThatReachesAnUncommittedRebuildWaitsForItAndAddsItself(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customerWithAnOrder();
            $unpaid = $this->order($customer, '2031-05-04 13:00:00+00:00', added: true);
            $flags = self::flags();

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::payment($unpaid, afterFlag: $flags.'/rebuilt'),
                    self::rebuild(holdUntilAnotherWaits: true, flag: $flags.'/rebuilt'),
                ]);
            } finally {
                self::removeFlags($flags);
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame(['paid', 'rebuilt, another waited'], $outcomes, $message);
            self::assertSame($this->allFromTheOrders(), $this->allSummaries(), $message);
            self::assertSame(1, $this->summaryOf($customer)['paid_count'], $message);
        }
    }

    public function testManyPlacementsAndPaymentsAlongsideRepeatedRebuildsLeaveTheSummariesOfTheOrders(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customers = [];
            $unpaid = [];
            for ($i = 0; $i < 6; ++$i) {
                $customers[] = $customer = $this->customerWithAnOrder();
                $unpaid[] = $this->order($customer, '2031-05-04 13:00:00+00:00', added: true);
            }
            // Placements of known customers and of new ones (their first order), in both processes.
            $placements = [];
            for ($i = 0; $i < 12; ++$i) {
                $placements[] = [0 === $i % 3 ? $this->customer() : $customers[$i % 6], $this->orderId(), \sprintf('p%d@example.com', $i), \sprintf('2031-05-05 10:00:%02d+00:00', $i)];
            }
            $flags = self::flags();

            try {
                $outcomes = ParallelProcesses::run($this->control, [
                    self::work(\array_slice($placements, 0, 6), \array_slice($unpaid, 0, 3), $flags.'/first'),
                    self::work(\array_slice($placements, 6), \array_slice($unpaid, 3), $flags.'/second'),
                    self::rebuild(untilFlags: [$flags.'/first', $flags.'/second']),
                ]);
            } finally {
                self::removeFlags($flags);
            }

            $message = 'round '.$round.': '.implode('; ', $outcomes);
            self::assertSame(['placed 6, paid 3', 'placed 6, paid 3'], \array_slice($outcomes, 0, 2), $message);
            self::assertMatchesRegularExpression('/^rebuilt \d+ times while the orders came in$/', $outcomes[2], $message);
            self::assertSame($this->allFromTheOrders(), $this->allSummaries(), $message);
        }
    }

    /**
     * A customer with one committed order and its summary, as a placement left them.
     */
    private function customerWithAnOrder(): string
    {
        $customer = $this->customer();
        $this->order($customer, '2031-05-04 12:00:00+00:00', added: true);

        return $customer;
    }

    /**
     * A committed order of the customer, new; with $added also added to its summary, as PlaceOrder does.
     */
    private function order(string $customer, string $placedAt, bool $added = false): string
    {
        $orderId = $this->orderId();
        OrderingFixture::order($this->control, email: 'old@example.com', id: $orderId, customerId: $customer, placedAt: $placedAt);
        if ($added) {
            new DbalCustomerSummaries($this->control)->addOrder($customer, OrderId::fromString($orderId), Contact::of(EmailAddress::fromString('old@example.com'), 'Jan Kowalski', '600100200'), new \DateTimeImmutable($placedAt));
        }

        return $orderId;
    }

    private function customer(): string
    {
        $customer = 'kc-concurrent-'.bin2hex(random_bytes(6));
        $this->customers[] = $customer;

        return $customer;
    }

    private function orderId(): string
    {
        $orderId = Uuid::v7()->toRfc4122();
        $this->orders[] = $orderId;

        return $orderId;
    }

    /**
     * Places an order of the customer as PlaceOrder writes it: the order and its addition to the summary in one
     * transaction of the participant. After $afterFlag appears, if given; with $holdUntilAnotherWaits the transaction
     * stays open after the addition until another backend waits for a lock (at most 10 s), then commits.
     *
     * @return \Closure(): (\Closure(): string)
     */
    private static function placement(string $customer, string $orderId, string $email, string $placedAt, bool $holdUntilAnotherWaits = false, ?string $flag = null, ?string $afterFlag = null): \Closure
    {
        return static function () use ($customer, $orderId, $email, $placedAt, $holdUntilAnotherWaits, $flag, $afterFlag): \Closure {
            [$connection] = self::participant();

            return static function () use ($connection, $customer, $orderId, $email, $placedAt, $holdUntilAnotherWaits, $flag, $afterFlag): string {
                if (null !== $afterFlag && !self::await(static fn (): bool => is_file($afterFlag))) {
                    return 'the flag did not appear';
                }
                $connection->beginTransaction();
                OrderingFixture::order($connection, email: $email, id: $orderId, customerId: $customer, placedAt: $placedAt);
                new DbalCustomerSummaries($connection)->addOrder($customer, OrderId::fromString($orderId), Contact::of(EmailAddress::fromString($email), 'Jan Kowalski', '600100200'), new \DateTimeImmutable($placedAt));
                if (null !== $flag) {
                    touch($flag);
                }
                $waited = $holdUntilAnotherWaits && self::await(static fn (): bool => self::anotherWaits($connection));
                $connection->commit();

                return $waited ? 'placed, another waited' : 'placed';
            };
        };
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
     * Places the orders (each as PlaceOrder writes it, in a transaction of its own) and pays the others through
     * MarkOrderPaid, alternately, then leaves $flag.
     *
     * @param list<array{string, string, string, string}> $placements customer, order id, email, moment
     * @param list<string>                                $payments   the orders to pay
     *
     * @return \Closure(): (\Closure(): string)
     */
    private static function work(array $placements, array $payments, string $flag): \Closure
    {
        return static function () use ($placements, $payments, $flag): \Closure {
            [$connection, $container] = self::participant();
            $bus = $container->get('command.bus');
            \assert($bus instanceof MessageBusInterface);

            return static function () use ($connection, $bus, $placements, $payments, $flag): string {
                $placed = 0;
                $paid = 0;
                try {
                    foreach ($placements as $index => [$customer, $orderId, $email, $placedAt]) {
                        $connection->transactional(static function (Connection $connection) use ($customer, $orderId, $email, $placedAt): void {
                            OrderingFixture::order($connection, email: $email, id: $orderId, customerId: $customer, placedAt: $placedAt);
                            new DbalCustomerSummaries($connection)->addOrder($customer, OrderId::fromString($orderId), Contact::of(EmailAddress::fromString($email), 'Jan Kowalski', '600100200'), new \DateTimeImmutable($placedAt));
                        });
                        ++$placed;
                        if (isset($payments[$index])) {
                            $bus->dispatch(new MarkOrderPaid($payments[$index], Uuid::v7()->toRfc4122(), self::GROSS, 'PLN', new \DateTimeImmutable()));
                            ++$paid;
                        }
                    }
                } finally {
                    touch($flag);
                }

                return \sprintf('placed %d, paid %d', $placed, $paid);
            };
        };
    }

    /**
     * Runs the customer rebuild: once, or again and again until all $untilFlags appear (at most 10 s). After
     * $afterFlag appears, if given; with $holdUntilAnotherWaits it runs inside a transaction of the participant, which
     * stays open after the rebuild (holding the locks of the rows) until another backend waits for a lock (at most
     * 10 s), then commits.
     *
     * @param list<string> $untilFlags
     *
     * @return \Closure(): (\Closure(): string)
     */
    private static function rebuild(bool $holdUntilAnotherWaits = false, ?string $flag = null, ?string $afterFlag = null, array $untilFlags = []): \Closure
    {
        return static function () use ($holdUntilAnotherWaits, $flag, $afterFlag, $untilFlags): \Closure {
            [$connection, $container] = self::participant();
            $rebuild = $container->get(CustomerRebuild::class);
            \assert($rebuild instanceof CustomerRebuild);

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

                    return \sprintf('rebuilt %d times while the orders came in', $runs);
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
        $directory = sys_get_temp_dir().'/customer-summaries-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700));

        return $directory;
    }

    private static function removeFlags(string $directory): void
    {
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }

    /**
     * The email, the number of orders and the first and last moment (UTC) of the summary of the customer.
     *
     * @return list<mixed>
     */
    private function summary(string $customer): array
    {
        $row = $this->control->fetchNumeric(
            "SELECT email, order_count, to_char(first_order_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), to_char(last_order_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM ordering_customer WHERE customer_id = ?",
            [$customer],
        );

        return false === $row ? [] : $row;
    }

    /**
     * The same of the orders of the customer: the email of the newest one (by placed_at, then by id).
     *
     * @return list<mixed>
     */
    private function fromTheOrders(string $customer): array
    {
        $row = $this->control->fetchNumeric(
            "SELECT (SELECT email FROM ordering_order WHERE customer_id = :c ORDER BY placed_at DESC, id DESC LIMIT 1), CAST(COUNT(*) AS int),
                    to_char(MIN(placed_at) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), to_char(MAX(placed_at) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')
             FROM ordering_order WHERE customer_id = :c",
            ['c' => $customer],
        );

        return false === $row ? [] : $row;
    }

    /**
     * Every summary of the test, in the order of the customer ids.
     *
     * @return list<list<mixed>>
     */
    private function allSummaries(): array
    {
        return $this->control->fetchAllNumeric(
            "SELECT customer_id, email, full_name, phone, order_count, paid_count, CAST(paid_gross AS int),
                    to_char(first_order_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), to_char(last_order_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'),
                    CAST(last_order_id AS text)
             FROM ordering_customer ORDER BY customer_id",
        );
    }

    /**
     * What the orders of the customers say, the same columns: the contact of the newest order (by placed_at, then by
     * id), the numbers of all and of the paid orders, the gross total of the paid ones, the first and the last moment.
     *
     * @return list<list<mixed>>
     */
    private function allFromTheOrders(): array
    {
        return $this->control->fetchAllNumeric(
            "SELECT a.customer_id, n.email, n.full_name, n.phone, a.order_count, a.paid_count, a.paid_gross, a.first_order_at, a.last_order_at, n.id
             FROM (
                 SELECT customer_id, CAST(COUNT(*) AS int) AS order_count, CAST(COUNT(paid_at) AS int) AS paid_count,
                        CAST(COALESCE(SUM(total_gross) FILTER (WHERE paid_at IS NOT NULL), 0) AS int) AS paid_gross,
                        to_char(MIN(placed_at) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS first_order_at,
                        to_char(MAX(placed_at) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS last_order_at
                 FROM ordering_order WHERE owner_type = 'customer' GROUP BY customer_id
             ) AS a
             JOIN (
                 SELECT DISTINCT ON (customer_id) customer_id, email, full_name, phone, CAST(id AS text) AS id
                 FROM ordering_order WHERE owner_type = 'customer' ORDER BY customer_id, placed_at DESC, id DESC
             ) AS n ON n.customer_id = a.customer_id
             ORDER BY a.customer_id",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryOf(string $customer): array
    {
        $row = $this->control->fetchAssociative('SELECT order_count, paid_count FROM ordering_customer WHERE customer_id = ?', [$customer]);
        self::assertIsArray($row);

        return $row;
    }
}
