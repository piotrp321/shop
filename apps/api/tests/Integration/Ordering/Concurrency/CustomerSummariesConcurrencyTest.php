<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use App\Kernel;
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
use Symfony\Component\Uid\Uuid;

/**
 * The customer summaries written concurrently, each participant in a process of its own with a kernel and a
 * connection of its own (ParallelProcesses): whatever the interleaving, a summary is what its orders say, so no order
 * is lost or counted twice and the contact is the one of the newest order (spec ADMIN-FEATURES, section 5).
 *
 * Two placements of one customer, as PlaceOrder writes them (the order and its addition in one transaction): the newer
 * one adds itself first and holds its row until the older one waits for it, so the older one commits last and must
 * keep the contact of the newer one; and the other way round. The rows commit, so the orders and the summaries of the
 * test are deleted after it.
 */
final class CustomerSummariesConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 3;
    private const float WAIT_SECONDS = 10.0;

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
}
