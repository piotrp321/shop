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
        // The kernel of the test when it has one (a functional test keeps the one of its client), else a booted one.
        $kernel = self::$kernel ?? self::bootKernel();
        $tester = new CommandTester(new Application($kernel)->find($name));
        $tester->execute($options ? $input + ['--demo-profile' => 'small', '--anchor' => self::ANCHOR] : $input);

        return $tester;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function succeeds(string $name, array $input = [], bool $options = true): CommandTester
    {
        $tester = $this->step($name, $input, $options);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $name.': '.$tester->getDisplay());

        return $tester;
    }

    /**
     * demo-data:verify without the account checks: the steps of the database make no accounts, and the realms of the
     * stack are never used by the tests (AccountsVerifyTest checks the accounts on temporary realms).
     *
     * @param array<string, mixed> $input
     */
    private function verify(array $input = []): CommandTester
    {
        return $this->step('demo-data:verify', $input + ['--no-accounts' => true]);
    }

    private function catalogSteps(): void
    {
        $this->succeeds('demo-data:categories');
        $this->succeeds('demo-data:products');
        $this->succeeds('demo-data:photos');
        $this->analyze();
    }

    /**
     * demo-data:analyze in the transaction of the test, after the steps that load the rows the test then reads.
     * PostgreSQL plans the joins of those reads well only with the statistics of the loaded tables, and the rows of a test
     * are never committed: a vacuum of another session (autovacuum, after the rollback of an earlier test) counts none
     * of them and leaves the planner expecting empty tables, so every join becomes a nested loop over whole tables
     * (StepsPlannerStatisticsTest). ANALYZE counts the rows of its own transaction and keeps its lock until the rollback,
     * so autovacuum skips these tables for the rest of the test.
     */
    private function analyze(): void
    {
        $this->succeeds('demo-data:analyze', [], false);
    }

    /**
     * The steps of the catalog and of the prices (step 3: the prices with their history, then the promotions and the
     * discount codes) and the ANALYZE that bin/generate-demo runs before demo-data:verify: the checks of the prices join
     * the big tables ({@see analyze()}).
     */
    private function generatedSteps(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $this->analyze();
    }

    /**
     * Every step of the database in the order of bin/generate-demo (the accounts are left out: they live in Keycloak),
     * with the ANALYZE before demo-data:verify.
     */
    private function allSteps(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $this->orderSteps();
        $this->analyze();
    }

    /**
     * The steps after the accounts (5 to 7): the orders, the stock after them and the number sequence.
     */
    private function orderSteps(): void
    {
        $this->succeeds('demo-data:orders');
        $this->succeeds('demo-data:stock');
        $this->succeeds('demo-data:order-numbers');
    }

    private function pricingSteps(): void
    {
        $this->succeeds('demo-data:prices');
        $this->succeeds('demo-data:promotions');
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
