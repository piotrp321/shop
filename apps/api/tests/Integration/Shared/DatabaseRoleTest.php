<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseRoleTest extends KernelTestCase
{
    public function testApplicationConnectsAsShopRoleWithoutSuperuserRights(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $role = $connection->fetchAssociative(
            'SELECT current_user AS name, rolsuper, rolcreaterole, rolcreatedb FROM pg_roles WHERE rolname = current_user',
        );

        self::assertSame(['name' => 'shop', 'rolsuper' => false, 'rolcreaterole' => false, 'rolcreatedb' => false], $role);
    }

    public function testShopRoleOwnsTheTestDatabaseAndItsTables(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        self::assertSame('shop_test', $connection->fetchOne('SELECT current_database()'));
        self::assertSame('shop', $connection->fetchOne(
            'SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = current_database()',
        ));
        self::assertSame([], $connection->fetchFirstColumn(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tableowner <> 'shop'",
        ));
    }
}
