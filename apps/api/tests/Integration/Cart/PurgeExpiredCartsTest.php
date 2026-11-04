<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart;

use App\Cart\Application\Command\PurgeExpiredCarts;
use App\Cart\Application\Command\PurgeExpiredCartsHandler;
use App\Cart\Infrastructure\Persistence\Doctrine\DbalExpiredCarts;
use App\Cart\Infrastructure\SystemClock;
use App\Cart\UI\Console\PurgeExpiredCartsCommand;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * cart:purge-expired on shop_test. The tests run in the DAMA transaction, so each batch of the command is a savepoint
 * of it (rolled back after the test); the one test that needs a second connection commits a single cart of its own.
 */
final class PurgeExpiredCartsTest extends KernelTestCase
{
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-00000000000a';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-00000000000b';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM cart_cart');
    }

    public function testRemovesOnlyExpiredCartsWithTheirLines(): void
    {
        $expiredGuest = CartFixture::cart($this->connection, expiresAt: '-1 minute', lines: [[self::A, 1], [self::B, 2]]);
        $mergedGuest = CartFixture::cart($this->connection, expiresAt: '-2 days', status: 'merged');
        $expiredCustomer = CartFixture::cart($this->connection, customerId: 'kc-sub-purge-1', expiresAt: '-200 days', lines: [[self::A, 5]]);
        $guest = CartFixture::cart($this->connection, expiresAt: '+1 minute', lines: [[self::A, 3]]);
        $customer = CartFixture::cart($this->connection, customerId: 'kc-sub-purge-2', expiresAt: '+100 days', lines: [[self::B, 4]]);
        $tester = $this->command();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertEqualsCanonicalizing([$guest, $customer], $this->connection->fetchFirstColumn('SELECT id FROM cart_cart'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_line WHERE cart_id IN (?, ?, ?)', [$expiredGuest, $mergedGuest, $expiredCustomer]));
        self::assertSame([[self::A, 3]], CartFixture::lines($this->connection, $guest));
        self::assertSame([[self::B, 4]], CartFixture::lines($this->connection, $customer));
        self::assertStringContainsString('Removed 3 expired carts in 1 batch.', $this->display($tester));
    }

    public function testRemovesInBatchesOfTheGivenSize(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            CartFixture::cart($this->connection, expiresAt: \sprintf('-%d days', $i), lines: [[self::A, $i]]);
        }
        $alive = CartFixture::cart($this->connection, expiresAt: '+1 day');
        $tester = $this->command();

        self::assertSame(0, $tester->execute(['--batch-size' => '2']), $tester->getDisplay());

        $display = $this->display($tester);
        self::assertStringContainsString('Batch 1: 2 carts removed.', $display);
        self::assertStringContainsString('Batch 2: 2 carts removed.', $display);
        self::assertStringContainsString('Batch 3: 1 cart removed.', $display);
        self::assertStringNotContainsString('Batch 4', $display);
        self::assertStringContainsString('Removed 5 expired carts in 3 batches.', $display);
        self::assertSame([$alive], $this->connection->fetchFirstColumn('SELECT id FROM cart_cart'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_line'));
    }

    public function testNothingToRemoveIsASuccess(): void
    {
        $alive = CartFixture::cart($this->connection, expiresAt: '+1 day', lines: [[self::A, 1]]);
        $tester = $this->command();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertStringContainsString('No expired carts.', $this->display($tester));
        self::assertSame([$alive], $this->connection->fetchFirstColumn('SELECT id FROM cart_cart'));
        self::assertSame(0, new CommandTester($this->containerCommand())->execute([]));
    }

    public function testABatchSizeBelowOneIsRefusedWithoutChanges(): void
    {
        CartFixture::cart($this->connection, expiresAt: '-1 day');
        $tester = $this->command();

        foreach (['0', '-5', 'many', '1.5'] as $size) {
            self::assertSame(1, $tester->execute(['--batch-size' => $size]), $size);
            self::assertStringContainsString('The batch size must be a positive integer.', $this->display($tester));
        }
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart'));
    }

    /**
     * The batches are 2, 2 and 1 carts by expiry; deleting the last cart fails. The exit code 2 is checked first;
     * then that the first two batches were committed (here: released savepoints) before the failure and the failed
     * one was rolled back with its own transaction only.
     */
    public function testAFailureInALaterBatchStopsWithTwoAndKeepsTheBatchesRemovedBefore(): void
    {
        $ids = [];
        for ($i = 5; $i >= 1; --$i) {
            $ids[$i] = CartFixture::cart($this->connection, expiresAt: \sprintf('-%d days', $i), lines: [[self::A, $i]]);
        }
        // Expiry order: -5 days (id 5) first, -1 day (id 1) last.
        $this->connection->executeStatement("CREATE FUNCTION cart_test_fail_purge() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure of a purge'; END \$\$");
        $this->connection->executeStatement(\sprintf("CREATE TRIGGER cart_test_fail_purge BEFORE DELETE ON cart_cart FOR EACH ROW WHEN (OLD.id = '%s'::uuid) EXECUTE FUNCTION cart_test_fail_purge()", $ids[1]));
        $tester = $this->command();

        self::assertSame(2, $tester->execute(['--batch-size' => '2']), $tester->getDisplay());

        $display = $this->display($tester);
        self::assertStringContainsString('Batch 1: 2 carts removed.', $display);
        self::assertStringContainsString('Batch 2: 2 carts removed.', $display);
        self::assertStringContainsString('The purge stopped:', $display);
        self::assertStringContainsString('forced failure of a purge', $display);
        self::assertSame([$ids[1]], $this->connection->fetchFirstColumn('SELECT id FROM cart_cart'));
        self::assertSame([[self::A, 1]], CartFixture::lines($this->connection, $ids[1]));
    }

    /**
     * Every statement fails to connect. The exit code 2 and the message are checked first; the second check (the
     * cart is still there) uses the test's own connection, so it shows only that this test did not remove it.
     */
    public function testUnavailableDatabaseStopsWithTwo(): void
    {
        CartFixture::cart($this->connection, expiresAt: '-1 day', lines: [[self::A, 1]]);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection->getParams();
        // Nothing listens there: every statement fails to connect, as when PostgreSQL is down. A plain DBAL
        // configuration, so the DAMA static connection is not used.
        $unreachable = DriverManager::getConnection(['host' => '127.0.0.1', 'port' => 1] + $params, new DbalConfiguration());
        $handler = new PurgeExpiredCartsHandler(new DbalExpiredCarts($unreachable), new SystemClock());
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([PurgeExpiredCarts::class => [$handler]]))]);
        $application = new ConsoleApplication();
        $application->addCommand(new PurgeExpiredCartsCommand($bus));
        $tester = new CommandTester($application->find('cart:purge-expired'));

        self::assertSame(2, $tester->execute([]), $tester->getDisplay());

        $display = $this->display($tester);
        self::assertStringContainsString('The purge stopped:', $display);
        self::assertStringContainsString('Connection refused', $display);
    }

    /**
     * A customer's command locks the cart row and renews expires_at (touch). The purge does not wait for that lock:
     * it skips the locked row and removes the other expired cart. After the renewal is committed, a second run finds
     * nothing expired and the cart stays; this part shows the outcome, not that the outer expires_at condition of the
     * DELETE is what protects a cart renewed while the statement runs.
     */
    public function testACartLockedByAConcurrentChangeIsSkippedAndStaysAfterItsRenewal(): void
    {
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection->getParams();
        $other = DriverManager::getConnection($params, new DbalConfiguration());
        $locked = CartFixture::cart($other, customerId: 'kc-sub-purge-locked', expiresAt: '-1 day', lines: [[self::A, 2]]);
        try {
            // Fails the test instead of hanging it (both connections live in this process) if the purge waits.
            $this->connection->executeStatement("SET LOCAL lock_timeout = '3s'");
            $free = CartFixture::cart($this->connection, expiresAt: '-1 day');
            $other->beginTransaction();
            $other->executeStatement('SELECT id FROM cart_cart WHERE id = ? FOR UPDATE', [$locked]);
            $tester = $this->command();

            self::assertSame(0, $tester->execute([]), $tester->getDisplay());

            self::assertStringContainsString('Removed 1 expired cart in 1 batch.', $this->display($tester));
            self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE id = ?', [$free]));
            $other->executeStatement("UPDATE cart_cart SET expires_at = now() + interval '180 days', updated_at = now() WHERE id = ?", [$locked]);
            $other->commit();

            self::assertSame(0, $tester->execute([]), $tester->getDisplay());

            self::assertStringContainsString('No expired carts.', $this->display($tester));
            self::assertSame([[self::A, 2]], CartFixture::lines($this->connection, $locked));
        } finally {
            if ($other->isTransactionActive()) {
                $other->rollBack();
            }
            CartFixture::delete($other, [$locked]);
            $other->close();
        }
    }

    private function command(): CommandTester
    {
        return new CommandTester($this->containerCommand());
    }

    private function containerCommand(): \Symfony\Component\Console\Command\Command
    {
        return new Application(self::$kernel ?? self::bootKernel())->find('cart:purge-expired');
    }

    private function display(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }
}
