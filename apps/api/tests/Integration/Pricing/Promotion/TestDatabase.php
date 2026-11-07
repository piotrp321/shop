<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Promotion;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * A connection of the test's own to the test database (the one of config/packages/doctrine.yaml), without the kernel:
 * for the adapters that only need PostgreSQL, each on a transaction the test begins and rolls back.
 */
final class TestDatabase
{
    public static function connect(): Connection
    {
        $env = static fn (string $name): string => \is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : (getenv($name) ?: throw new \RuntimeException($name.' is not set.'));

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $env('SHOP_DB_HOST'),
            'dbname' => $env('SHOP_DB_NAME').'_test',
            'user' => $env('SHOP_DB_USER'),
            'password' => $env('SHOP_DB_PASSWORD'),
        ], new DbalConfiguration());
    }
}
