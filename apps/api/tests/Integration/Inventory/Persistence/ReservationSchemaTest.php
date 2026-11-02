<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The schema keeps the reservation tables consistent whatever writes to them: shop_test, rolled back after each test.
 */
final class ReservationSchemaTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testALineWithoutItsReservationIsRejected(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection->executeStatement(
            'INSERT INTO inventory_reservation_line (reservation_key, product_id, quantity) VALUES (?, ?, 1)',
            ['test:'.Uuid::v7()->toRfc4122(), (string) Uuid::v7()],
        );
    }

    public function testAReservationWithLinesCannotBeDeleted(): void
    {
        $key = 'test:'.Uuid::v7()->toRfc4122();
        $this->connection->executeStatement("INSERT INTO inventory_reservation (reservation_key, status, created_at, updated_at) VALUES (?, 'active', now(), now())", [$key]);
        $this->connection->executeStatement('INSERT INTO inventory_reservation_line (reservation_key, product_id, quantity) VALUES (?, ?, 1)', [$key, (string) Uuid::v7()]);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection->executeStatement('DELETE FROM inventory_reservation WHERE reservation_key = ?', [$key]);
    }
}
