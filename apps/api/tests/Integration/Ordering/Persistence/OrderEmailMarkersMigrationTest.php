<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderPaid;
use App\Ordering\Domain\OrderPlaced;
use App\Ordering\Infrastructure\Mail\SymfonyOrderConfirmationMailer;
use App\Ordering\Infrastructure\Mail\SymfonyOrderStatusMailer;
use App\Tests\Unit\Ordering\SpyOrderConfirmationMailer;
use App\Tests\Unit\Ordering\SpyOrderStatusMailer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Runs the real migration that moves the markers of the emails out of the order table, in the transaction DAMA rolls
 * back after each test: its own down() first puts the marker columns back (PostgreSQL DDL is transactional), orders
 * with markers are inserted in that shape, its up() is executed and the result is read. Nothing is committed.
 */
final class OrderEmailMarkersMigrationTest extends KernelTestCase
{
    public const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261107130000MoveOrderEmailMarkers.php';
    private const string PAID = '0192f0c4-7c1a-7c4e-9a6e-000000000011';
    private const string CANCELLED = '0192f0c4-7c1a-7c4e-9a6e-000000000012';
    private const string UNSENT = '0192f0c4-7c1a-7c4e-9a6e-000000000013';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testTheMarkersOfExistingOrdersMoveToTheNotificationsAndTheColumnsGo(): void
    {
        self::runMigration($this->connection, 'down');
        $this->insertOrders();

        self::runMigration($this->connection, 'up');

        self::assertSame([
            [self::PAID, 'confirmation', null, '2026-11-05 12:00:05'],
            [self::PAID, 'payment_received', null, '2026-11-05 12:10:07'],
            [self::CANCELLED, 'cancelled', null, '2026-11-05 12:35:09'],
            [self::CANCELLED, 'confirmation', null, '2026-11-05 12:00:06'],
        ], $this->connection->fetchAllNumeric("SELECT order_id, kind, claimed_until, to_char(sent_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM ordering_order_notification ORDER BY order_id, kind"));
        self::assertSame([], $this->markerColumns());
    }

    public function testNoEmailGoesOutASecondTimeAfterTheMigration(): void
    {
        self::runMigration($this->connection, 'down');
        $this->insertOrders();
        self::runMigration($this->connection, 'up');
        $confirmations = new SpyOrderConfirmationMailer();
        $statuses = new SpyOrderStatusMailer();
        self::getContainer()->set(SymfonyOrderConfirmationMailer::class, $confirmations);
        self::getContainer()->set(SymfonyOrderStatusMailer::class, $statuses);
        $at = new \DateTimeImmutable('2026-11-05 13:00:00+00:00');

        foreach ([
            new OrderPlaced(self::PAID, $at),
            new OrderPaid(self::PAID, $at),
            new OrderPlaced(self::CANCELLED, $at),
            new OrderCancelled(self::CANCELLED, 'payment_timeout', $at),
            new OrderPlaced(self::UNSENT, $at),
        ] as $event) {
            $this->deliver($event);
        }

        self::assertSame([self::UNSENT], array_map(static fn ($order): string => $order->orderId, $confirmations->sent), 'only the order without the marker gets its confirmation');
        self::assertSame([], $statuses->sent);
    }

    public function testDownPutsTheMarkersBackIntoTheOrderTable(): void
    {
        self::runMigration($this->connection, 'down');
        $this->insertOrders();
        self::runMigration($this->connection, 'up');

        self::runMigration($this->connection, 'down');

        self::assertSame([
            [self::PAID, '2026-11-05 12:00:05', '2026-11-05 12:10:07', null],
            [self::CANCELLED, '2026-11-05 12:00:06', null, '2026-11-05 12:35:09'],
            [self::UNSENT, null, null, null],
        ], $this->connection->fetchAllNumeric(
            "SELECT id, to_char(confirmation_sent_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), to_char(payment_notification_sent_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), to_char(cancellation_notification_sent_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM ordering_order ORDER BY id",
        ));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM ordering_order_notification'));
    }

    /**
     * Runs up() or down() of the migration on the connection; tests of older migrations call it with `down` first,
     * to get the order table back to the shape they start from.
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
     * Three guest orders in the shape before the migration, one line each: paid with the confirmation and the payment
     * email sent, cancelled with the confirmation and the cancellation email sent, and new without any email.
     */
    private function insertOrders(): void
    {
        $this->insertOrder(self::PAID, "'paid'", "'2026-11-05 12:10:05+00', 'a0000000-0000-7000-8000-000000000001', NULL, NULL", "'2026-11-05 12:00:05+00', '2026-11-05 12:10:07+00', NULL");
        $this->insertOrder(self::CANCELLED, "'cancelled'", "NULL, NULL, '2026-11-05 12:35:00+00', 'payment_timeout'", "'2026-11-05 12:00:06+00', NULL, '2026-11-05 12:35:09+00'");
        $this->insertOrder(self::UNSENT, "'new'", 'NULL, NULL, NULL, NULL', 'NULL, NULL, NULL');
    }

    private function insertOrder(string $id, string $status, string $payment, string $markers): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ordering_order (id, number, status, owner_type, access_token, email, full_name, phone, street, postal_code, city, country, currency, total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, payment_deadline, paid_at, payment_id, cancelled_at, cancellation_reason, confirmation_sent_at, payment_notification_sent_at, cancellation_notification_sent_at, version)
             VALUES (?, ?, {$status}, 'guest', ?, 'jan@example.com', 'Jan Kowalski', '+48600100200', 'Długa 1', '00-950', 'Warszawa', 'PL', 'PLN', 1000, 230, 1230, ?, ?, '2026-11-05 12:00:00+00', '2026-11-05 12:30:00+00', {$payment}, {$markers}, 3)",
            [$id, 'R-9'.substr($id, -5), str_repeat('t', 43), 'migration-'.$id, str_repeat('a', 64)],
        );
        $this->connection->executeStatement(
            "INSERT INTO ordering_order_line (id, order_id, position, variant_id, product_id, product_name, variant_label, quantity, unit_net, unit_vat, unit_gross, vat_rate_bp, tax_class, line_net, line_vat, line_gross)
             VALUES (?, ?, 0, 'b0000000-0000-7000-8000-000000000001', 'c0000000-0000-7000-8000-000000000001', 'Kubek', 'Standard', 1, 1000, 230, 1230, 2300, 'standard', 1000, 230, 1230)",
            ['d0000000-0000-7000-8000-'.substr($id, -12), $id],
        );
    }

    /**
     * @return list<mixed>
     */
    private function markerColumns(): array
    {
        return $this->connection->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'ordering_order' AND column_name IN ('confirmation_sent_at', 'payment_notification_sent_at', 'cancellation_notification_sent_at')",
        );
    }

    private function deliver(object $event): void
    {
        $bus = self::getContainer()->get('event.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        // As the worker hands over a message received from `events`.
        $bus->dispatch(new Envelope($event, [new ReceivedStamp('events')]));
    }
}
