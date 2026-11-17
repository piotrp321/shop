<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Copy\Sql;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The steps of the generator run in the process of the test, on shop_test, in the transaction of the test (rolled back
 * after it): no bin/seed-demo, no purge of the cache of the store.
 */
trait RunsDemoDataSteps
{
    /** The tables of the catalog the generator fills. */
    private const array CATALOG_TABLES = ['catalog_category', 'catalog_product', 'catalog_product_variant', 'catalog_product_category'];

    private const string ANCHOR = '2026-11-17T00:00:00Z';

    /**
     * @param array<string, mixed> $input
     */
    private function step(string $name, array $input = [], bool $options = true): CommandTester
    {
        self::bootKernel();
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find($name));
        $tester->execute($options ? $input + ['--demo-profile' => 'small', '--anchor' => self::ANCHOR] : $input);

        return $tester;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function succeeds(string $name, array $input = []): CommandTester
    {
        $tester = $this->step($name, $input);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $name.': '.$tester->getDisplay());

        return $tester;
    }

    private function catalogSteps(): void
    {
        $this->succeeds('demo-data:categories');
        $this->succeeds('demo-data:products');
        $this->succeeds('demo-data:photos');
    }

    private function int(mixed $value): int
    {
        return Sql::int($value);
    }

    private function text(mixed $value): string
    {
        return Sql::string($value);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function emptyCatalog(): void
    {
        $this->connection()->executeStatement('TRUNCATE catalog_product_category, catalog_product_variant, catalog_product, catalog_category_redirect, catalog_category, catalog_category_reindex');
    }

    /**
     * An md5 per table over the rows in the order of their keys; the table of a fresh shop_test has no seed rows.
     *
     * @return array<string, string>
     */
    private function checksums(Connection $connection): array
    {
        $sums = [];
        foreach (self::CATALOG_TABLES as $table) {
            $order = 'catalog_product_category' === $table ? 't.product_id, t.category_id' : 't.id';
            $sums[$table] = Sql::string($connection->fetchOne(\sprintf("SELECT coalesce(md5(string_agg(t::text, '|' ORDER BY %s)), 'empty') FROM %s t", $order, $table)));
        }

        return $sums;
    }
}
