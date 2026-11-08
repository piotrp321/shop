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
 * Runs the real migration of the order lifecycle (staff transitions, refund, history of the statuses) in the
 * transaction DAMA rolls back after each test: its own down() first puts the order table back to its shape before it
 * (PostgreSQL DDL is transactional), orders of the payment cycle are inserted in that shape, its up() is executed and
 * the result is read. Nothing is committed.
 */
final class OrderLifecycleMigrationTest extends KernelTestCase
{
    public const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261107140000AddOrderLifecycle.php';
    private const string NEW = '0192f0c4-7c1a-7c4e-9a6e-000000000021';
    private const string PAID = '0192f0c4-7c1a-7c4e-9a6e-000000000022';
    private const string EXPIRED = '0192f0c4-7c1a-7c4e-9a6e-000000000023';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testExistingOrdersGetTheirHistoryFromTheirMoments(): void
    {
        self::runMigration($this->connection, 'down');
        $this->insertOrders();

        self::runMigration($this->connection, 'up');

        self::assertSame([
            [self::NEW, 0, null, 'new', '2026-11-05 12:00:00', 'customer', null, null, null],
            [self::PAID, 0, null, 'new', '2026-11-05 12:00:00', 'customer', null, null, null],
            [self::PAID, 1, 'new', 'paid', '2026-11-05 12:10:05', 'system', null, null, null],
            [self::EXPIRED, 0, null, 'new', '2026-11-05 12:00:00', 'customer', null, null, null],
            [self::EXPIRED, 1, 'new', 'cancelled', '2026-11-05 12:35:00', 'system', null, null, null],
        ], $this->connection->fetchAllNumeric(
            "SELECT order_id, position, from_status, to_status, to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), actor_type, staff_id, staff_username, note FROM ordering_order_status_change ORDER BY order_id, position",
        ));
        self::assertSame(
            [[self::NEW, 3], [self::PAID, 3], [self::EXPIRED, 3]],
            $this->connection->fetchAllNumeric('SELECT id, version FROM ordering_order ORDER BY id'),
            'the versions stay',
        );
    }

    public function testTheNewRulesAcceptACancellationAfterThePaymentAndRefuseBrokenStates(): void
    {
        self::runMigration($this->connection, 'down');
        $this->insertOrders();
        self::runMigration($this->connection, 'up');

        self::assertSame(1, $this->connection->executeStatement(
            "UPDATE ordering_order SET status = 'cancelled', cancelled_at = now(), cancellation_reason = 'staff', refund_amount = total_gross, refund_due_at = now() + interval '14 days' WHERE id = ?",
            [self::PAID],
        ), 'a paid order cancelled by staff keeps paid_at');

        foreach ([
            'shipped without paid_at' => "UPDATE ordering_order SET status = 'shipped', shipped_at = now() WHERE id = '".self::NEW."'",
            'returned without returned_at' => "UPDATE ordering_order SET status = 'returned', paid_at = now(), payment_id = gen_random_uuid(), shipped_at = now(), refund_amount = 1230, refund_due_at = now() WHERE id = '".self::NEW."'",
            'a refund without its reason' => "UPDATE ordering_order SET refund_amount = 1230, refund_due_at = now() WHERE id = '".self::NEW."'",
            'a cancellation by staff without paid_at' => "UPDATE ordering_order SET cancellation_reason = 'staff', refund_amount = 1230, refund_due_at = now() WHERE id = '".self::EXPIRED."'",
        ] as $case => $statement) {
            $this->connection->executeStatement('SAVEPOINT rule');
            try {
                $this->connection->executeStatement($statement);
                self::fail('Expected PostgreSQL to refuse: '.$case);
            } catch (DriverException $refused) {
                self::assertSame('23514', $refused->getSQLState(), $case.': '.$refused->getMessage());
            }
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT rule');
        }
    }

    public function testDownRemovesTheHistoryAndTheColumnsOfTheLifecycle(): void
    {
        self::runMigration($this->connection, 'down');

        self::assertFalse($this->connection->fetchOne("SELECT to_regclass('ordering_order_status_change') IS NOT NULL"));
        self::assertSame([], $this->connection->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'ordering_order' AND (column_name IN ('shipped_at', 'delivered_at', 'returned_at') OR column_name LIKE 'refund%')",
        ));
        $this->insertOrders();
        $this->connection->executeStatement('SAVEPOINT rule');
        try {
            $this->connection->executeStatement("UPDATE ordering_order SET status = 'cancelled', cancelled_at = now(), cancellation_reason = 'payment_timeout' WHERE id = ?", [self::PAID]);
            self::fail('Expected the rule of the payment cycle back: a paid order is never cancelled.');
        } catch (DriverException $refused) {
            self::assertSame('23514', $refused->getSQLState());
        }
        $this->connection->executeStatement('ROLLBACK TO SAVEPOINT rule');
    }

    /**
     * Runs up() or down() of the migration on the connection; tests of older migrations call it with `down` first,
     * to get the order table back to the shape they start from, and with `up` after their own up().
     */
    public static function runMigration(Connection $connection, string $direction): void
    {
        self::assertFileExists(self::MIGRATION_FILE);
        require_once self::MIGRATION_FILE;
        $class = 'DoctrineMigrations\\'.basename(self::MIGRATION_FILE, '.php');
        self::assertTrue(class_exists($class), $class);
        $migration = new $class($connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);

        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    /**
     * Three guest orders in the shape before the migration: new, paid and cancelled for want of a payment.
     */
    private function insertOrders(): void
    {
        $this->insertOrder(self::NEW, "'new'", 'NULL, NULL, NULL, NULL');
        $this->insertOrder(self::PAID, "'paid'", "'2026-11-05 12:10:05+00', 'a0000000-0000-7000-8000-000000000001', NULL, NULL");
        $this->insertOrder(self::EXPIRED, "'cancelled'", "NULL, NULL, '2026-11-05 12:35:00+00', 'payment_timeout'");
    }

    private function insertOrder(string $id, string $status, string $payment): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ordering_order (id, number, status, owner_type, access_token, email, full_name, phone, street, postal_code, city, country, currency, total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, payment_deadline, paid_at, payment_id, cancelled_at, cancellation_reason, version)
             VALUES (?, ?, {$status}, 'guest', ?, 'jan@example.com', 'Jan Kowalski', '+48600100200', 'Długa 1', '00-950', 'Warszawa', 'PL', 'PLN', 1000, 230, 1230, ?, ?, '2026-11-05 12:00:00+00', '2026-11-05 12:30:00+00', {$payment}, 3)",
            [$id, 'R-9'.substr($id, -5), str_repeat('t', 43), 'migration-'.$id, str_repeat('a', 64)],
        );
    }
}
