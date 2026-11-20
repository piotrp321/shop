<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The migrations of the delivery of orders (docs/features/delivery/spec.md, W11) in the transaction DAMA rolls back
 * after each test: the first adds the columns, the table of the VAT shares and the CHECK constraints of ordering_order
 * as NOT VALID (existing orders stay without a method and with a delivery of 0), the second validates them. After it
 * every CHECK refuses the row that breaks it. Nothing is committed.
 */
final class OrderDeliveryMigrationTest extends KernelTestCase
{
    private const string ADD = __DIR__.'/../../../../migrations/Version20261121100000AddOrderDelivery.php';
    private const string VALIDATE = __DIR__.'/../../../../migrations/Version20261121100100ValidateOrderDeliveryChecks.php';
    private const array CHECKS = [
        'ordering_order_delivery_amounts_check',
        'ordering_order_delivery_cost_check',
        'ordering_order_delivery_method_check',
        'ordering_order_delivery_template_check',
        'ordering_order_tracking_number_check',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testExistingOrdersStayWithoutAMethodAndWithADeliveryOfZeroAndTheChecksAreValidatedLater(): void
    {
        $this->runMigration(self::ADD, 'down');
        self::assertSame([], $this->connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'ordering_order' AND (column_name LIKE 'delivery%' OR column_name = 'tracking_number')"));
        $order = OrderingFixture::order($this->connection, paid: true);
        $totals = $this->connection->fetchNumeric('SELECT total_net, total_vat, total_gross FROM ordering_order WHERE id = ?', [$order]);

        $this->runMigration(self::ADD, 'up');

        self::assertSame([null, null, null, 0, 0, 0, null], $this->connection->fetchNumeric('SELECT delivery_method_id, delivery_method_name, delivery_tracking_url_template, delivery_gross, delivery_net, delivery_vat, tracking_number FROM ordering_order WHERE id = ?', [$order]));
        self::assertSame($totals, $this->connection->fetchNumeric('SELECT total_net, total_vat, total_gross FROM ordering_order WHERE id = ?', [$order]), 'nothing is computed anew');
        self::assertSame(array_fill_keys(self::CHECKS, false), $this->validated(), 'added NOT VALID: no scan of the table under the lock of the migration');

        $this->runMigration(self::VALIDATE, 'up');

        self::assertSame(array_fill_keys(self::CHECKS, true), $this->validated());
    }

    /**
     * @return iterable<string, array{string, string}> the statement and the SQLSTATE PostgreSQL refuses it with
     */
    public static function rowsBreakingTheRules(): iterable
    {
        yield 'a method without its name' => ['UPDATE ordering_order SET delivery_method_id = gen_random_uuid() WHERE id = :id', '23514'];
        yield 'a name without the method' => ["UPDATE ordering_order SET delivery_method_name = 'Kurier' WHERE id = :id", '23514'];
        yield 'a template without the method' => ["UPDATE ordering_order SET delivery_tracking_url_template = 'https://t.example/{numer}' WHERE id = :id", '23514'];
        yield 'net + VAT is not the gross of the delivery' => ["UPDATE ordering_order SET delivery_method_id = gen_random_uuid(), delivery_method_name = 'Kurier', delivery_gross = 1999, delivery_net = 1625, delivery_vat = 373 WHERE id = :id", '23514'];
        yield 'a negative VAT of the delivery' => ["UPDATE ordering_order SET delivery_method_id = gen_random_uuid(), delivery_method_name = 'Kurier', delivery_gross = 1999, delivery_net = 2000, delivery_vat = -1 WHERE id = :id", '23514'];
        yield 'a cost without a method' => ['UPDATE ordering_order SET delivery_gross = 1999, delivery_net = 1999 WHERE id = :id', '23514'];
        yield 'a tracking number before the shipping' => ["UPDATE ordering_order SET tracking_number = 'ABC123' WHERE id = :id", '23514'];
        yield 'a tracking number of 41 characters' => ["UPDATE ordering_order SET status = 'shipped', shipped_at = now(), tracking_number = repeat('A', 41) WHERE id = :id", '22001'];
        yield 'no cost of the delivery' => ['UPDATE ordering_order SET delivery_gross = NULL WHERE id = :id', '23502'];
        yield 'a share of 0' => ['INSERT INTO ordering_order_delivery_vat (order_id, vat_rate_bp, gross, net, vat) VALUES (:id, 2300, 0, 0, 0)', '23514'];
        yield 'a share with net + VAT other than its gross' => ['INSERT INTO ordering_order_delivery_vat (order_id, vat_rate_bp, gross, net, vat) VALUES (:id, 2300, 100, 80, 19)', '23514'];
        yield 'a share with a negative VAT' => ['INSERT INTO ordering_order_delivery_vat (order_id, vat_rate_bp, gross, net, vat) VALUES (:id, 2300, 100, 101, -1)', '23514'];
        yield 'two shares of one rate' => ['INSERT INTO ordering_order_delivery_vat (order_id, vat_rate_bp, gross, net, vat) VALUES (:id, 2300, 100, 81, 19), (:id, 2300, 100, 81, 19)', '23505'];
        yield 'a share of no order' => ['INSERT INTO ordering_order_delivery_vat (order_id, vat_rate_bp, gross, net, vat) VALUES (gen_random_uuid(), 2300, 100, 81, 19)', '23503'];
    }

    #[DataProvider('rowsBreakingTheRules')]
    public function testAfterTheValidationEveryCheckRefusesItsBreak(string $sql, string $sqlState): void
    {
        $order = OrderingFixture::order($this->connection, paid: true);

        try {
            $this->connection->executeStatement($sql, ['id' => $order]);
            self::fail('Expected PostgreSQL to refuse the statement.');
        } catch (DriverException $refused) {
            self::assertSame($sqlState, $refused->getSQLState(), $refused->getMessage());
        }
    }

    public function testAnOrderWithAMethodItsCostAndItsSharesAndAShippedOneWithANumberAreAccepted(): void
    {
        $order = OrderingFixture::order($this->connection, paid: true);

        $this->connection->executeStatement("UPDATE ordering_order SET delivery_method_id = gen_random_uuid(), delivery_method_name = 'Kurier', delivery_tracking_url_template = 'https://t.example/{numer}', delivery_gross = 1999, delivery_net = 1625, delivery_vat = 374, status = 'shipped', shipped_at = now(), tracking_number = repeat('A', 40) WHERE id = ?", [$order]);
        $this->connection->executeStatement('INSERT INTO ordering_order_delivery_vat (order_id, vat_rate_bp, gross, net, vat) VALUES (?, 2300, 1999, 1625, 374)', [$order]);

        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM ordering_order_delivery_vat WHERE order_id = ?', [$order]));
    }

    /**
     * @return array<string, bool> whether each CHECK of the delivery is validated, by name
     */
    private function validated(): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            "SELECT conname, convalidated FROM pg_constraint WHERE conrelid = 'ordering_order'::regclass AND conname IN (?) ORDER BY conname",
            [self::CHECKS],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $valid): bool => true === $valid, $rows);
    }

    private function runMigration(string $file, string $direction): void
    {
        require_once $file;
        $class = 'DoctrineMigrations\\'.basename($file, '.php');
        self::assertTrue(class_exists($class), $class);
        $migration = new $class($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);

        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
