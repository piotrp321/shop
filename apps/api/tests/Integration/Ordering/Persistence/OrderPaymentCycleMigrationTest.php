<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration of the payment cycle of orders on the real PostgreSQL tables, in the transaction DAMA rolls
 * back after each test: the migration's own down() first puts the order table back to its shape before it
 * (PostgreSQL DDL is transactional), orders are inserted in that shape, the migration's up() is executed and the
 * result is read. Nothing is committed.
 */
final class OrderPaymentCycleMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261106120000AddOrderPaymentCycle.php';
    private const string GUEST_1 = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string GUEST_2 = '0192f0c4-7c1a-7c4e-9a6e-000000000002';
    private const string CUSTOMER = '0192f0c4-7c1a-7c4e-9a6e-000000000003';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testExistingOrdersGetTheDeadlineAndGuestOrdersARandomToken(): void
    {
        $this->runMigration('down');
        $this->insertOrder(self::GUEST_1, null, '2026-11-05 12:00:00+00');
        $this->insertOrder(self::GUEST_2, null, '2026-11-05 12:10:30+00');
        $this->insertOrder(self::CUSTOMER, 'kc-sub-1', '2026-11-05 23:45:00+00');

        $this->runMigration('up');

        $rows = $this->connection->fetchAllAssociative("SELECT id, status, to_char(payment_deadline AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS deadline, access_token, paid_at, payment_id, cancelled_at, cancellation_reason, payment_notification_sent_at, cancellation_notification_sent_at FROM ordering_order ORDER BY id");
        self::assertSame(['2026-11-05 12:30:00', '2026-11-05 12:40:30', '2026-11-06 00:15:00'], array_column($rows, 'deadline'), 'placed_at + 30 minutes');
        self::assertSame(['new', 'new', 'new'], array_column($rows, 'status'));
        [$first, $second, $customer] = array_column($rows, 'access_token');
        self::assertIsString($first);
        self::assertIsString($second);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $first, '43 characters of base64url');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $second);
        self::assertNotSame($first, $second, 'every guest order gets a token of its own');
        self::assertNull($customer);
        foreach (['paid_at', 'payment_id', 'cancelled_at', 'cancellation_reason', 'payment_notification_sent_at', 'cancellation_notification_sent_at'] as $column) {
            self::assertSame([null, null, null], array_column($rows, $column), $column);
        }
    }

    public function testTheMigratedTableKeepsTheRulesOfThePaymentCycle(): void
    {
        $this->runMigration('down');
        $this->insertOrder(self::GUEST_1, null, '2026-11-05 12:00:00+00');

        $this->runMigration('up');

        foreach ([
            'UPDATE ordering_order SET access_token = NULL',
            "UPDATE ordering_order SET status = 'paid'",
            "UPDATE ordering_order SET status = 'cancelled'",
        ] as $statement) {
            $this->connection->executeStatement('SAVEPOINT rule');
            try {
                $this->connection->executeStatement($statement);
                self::fail('Expected PostgreSQL to refuse: '.$statement);
            } catch (DriverException $refused) {
                self::assertSame('23514', $refused->getSQLState(), $refused->getMessage());
            }
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT rule');
        }
    }

    public function testDownRemovesTheColumns(): void
    {
        $this->runMigration('down');

        self::assertSame([], $this->connection->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'ordering_order' AND column_name IN ('payment_deadline', 'access_token', 'paid_at', 'payment_id', 'cancelled_at', 'cancellation_reason', 'payment_notification_sent_at', 'cancellation_notification_sent_at')",
        ));
    }

    /**
     * An order in the shape before the migration, without lines (the migration does not read them).
     */
    private function insertOrder(string $id, ?string $customerId, string $placedAt): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ordering_order (id, number, status, owner_type, customer_id, email, full_name, phone, street, postal_code, city, country, currency, total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, version)
             VALUES (?, ?, 'new', ?, ?, 'jan@example.com', 'Jan Kowalski', '+48600100200', 'Długa 1', '00-950', 'Warszawa', 'PL', 'PLN', 1000, 230, 1230, ?, ?, ?, 1)",
            [$id, 'R-9'.substr($id, -5), null === $customerId ? 'guest' : 'customer', $customerId, 'migration-'.$id, str_repeat('a', 64), $placedAt],
        );
    }

    private function runMigration(string $direction): void
    {
        self::assertFileExists(self::MIGRATION_FILE);
        require_once self::MIGRATION_FILE;
        $class = 'DoctrineMigrations\\'.basename(self::MIGRATION_FILE, '.php');
        self::assertTrue(class_exists($class), $class);
        $migration = new $class($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);

        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
