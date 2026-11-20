<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Copy\Sql;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rows of the catalog, price, order and stock steps do not depend on the number of processes: bin/console processes
 * (APP_ENV=test) write the parts of the products, the prices and the orders with 1, 3 and 4 workers, and the checksum of
 * every table (without the random guest tokens and idempotency keys) is the same. The processes commit,
 * so the test reads and cleans the tables through a connection of its own, outside the transaction of the test; it
 * empties the tables before and after every run. Its own group, so a run that cannot start processes can leave it out.
 */
#[Group('demo-data-processes')]
final class ProcessCountDeterminismTest extends KernelTestCase
{
    private const string ANCHOR = '2026-11-17T00:00:00Z';
    /** The tables and the columns their rows are ordered by in the checksum. */
    private const array TABLES = [
        'catalog_category' => 't.id',
        'catalog_product' => 't.id',
        'catalog_product_variant' => 't.id',
        'catalog_product_category' => 't.product_id, t.category_id',
        'pricing_variant_price' => 't.variant_id',
        'pricing_price_history' => 't.id',
        'pricing_promotion' => 't.id',
        'pricing_promotion_variant' => 't.promotion_id, t.variant_id',
        'pricing_discount_code' => 't.id',
        'ordering_order' => 't.id',
        'ordering_order_line' => 't.id',
        'ordering_order_status_change' => 't.order_id, t.position',
        'payment_payment' => 't.id',
        'pricing_discount_code_usage' => 't.id',
        'inventory_stock_item' => 't.variant_id',
        'inventory_reservation' => 't.reservation_key',
        'inventory_reservation_line' => 't.reservation_key, t.variant_id',
        'inventory_stock_movement' => 't.id',
    ];
    /** The random columns, left out of the checksum: the guest tokens and the idempotency keys. */
    private const array RANDOM_COLUMNS = [
        'ordering_order' => ['access_token', 'idempotency_key'],
        'payment_payment' => ['idempotency_key'],
    ];

    private Connection $control;

    protected function setUp(): void
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
        $this->empty();
    }

    protected function tearDown(): void
    {
        $this->empty();
        $this->control->close();
        parent::tearDown();
    }

    public function testTheCatalogPriceOrderAndStockRowsAreTheSameWithOneThreeAndFourProcesses(): void
    {
        $sums = [];
        foreach ([1, 3, 4] as $processes) {
            $this->generate($processes);
            $sums[$processes] = $this->checksums();
            self::assertSame(2_000, Sql::int($this->control->fetchOne('SELECT count(*) FROM catalog_product')), $processes.' processes');
            self::assertGreaterThan(5_000, Sql::int($this->control->fetchOne('SELECT count(*) FROM pricing_variant_price')), $processes.' processes');
            self::assertSame(5_000, Sql::int($this->control->fetchOne('SELECT count(*) FROM ordering_order')), $processes.' processes');
            $this->empty();
        }

        self::assertNotContains('empty', $sums[1]);
        self::assertSame(array_keys(self::TABLES), array_keys($sums[1]));
        self::assertSame($sums[1], $sums[3]);
        self::assertSame($sums[1], $sums[4]);
    }

    private function generate(int $processes): void
    {
        $this->runConsole(['demo-data:categories']);

        $running = [];
        for ($worker = 0; $worker < $processes; ++$worker) {
            $running[$worker] = $this->start(['demo-data:products', '--worker='.$worker, '--workers='.$processes]);
        }
        $this->await($running, $processes, 'products');

        $running = [];
        for ($worker = 0; $worker < $processes; ++$worker) {
            $running[$worker] = $this->start(['demo-data:prices', '--worker='.$worker, '--workers='.$processes]);
        }
        $this->await($running, $processes, 'prices');
        $this->runConsole(['demo-data:promotions']);

        $running = [];
        for ($worker = 0; $worker < $processes; ++$worker) {
            $running[$worker] = $this->start(['demo-data:orders', '--worker='.$worker, '--workers='.$processes]);
        }
        $this->await($running, $processes, 'orders');
        $this->runConsole(['demo-data:stock']);
        $this->runConsole(['demo-data:order-numbers']);
    }

    /**
     * @param array<int, array{resource, array<int, resource>}> $running
     */
    private function await(array $running, int $processes, string $step): void
    {
        foreach ($running as $worker => [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
            $code = proc_close($process);
            self::assertSame(0, $code, \sprintf('%s, worker %d of %d: %s', $step, $worker, $processes, $output));
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function runConsole(array $arguments): void
    {
        [$process, $pipes] = $this->start($arguments);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $output);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{resource, array<int, resource>}
     */
    private function start(array $arguments): array
    {
        $command = [\PHP_BINARY, \dirname(__DIR__, 3).'/bin/console', ...$arguments, '--demo-profile=small', '--anchor='.self::ANCHOR, '--env=test', '--no-interaction'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    private function empty(): void
    {
        $this->control->executeStatement('TRUNCATE catalog_product_category, catalog_product_variant, catalog_product, catalog_category_redirect, catalog_category, pricing_variant_price, pricing_price_history, pricing_promotion_variant, pricing_promotion, pricing_discount_code_usage, pricing_discount_code,
            ordering_unpaid_order_claim, ordering_order_notification, ordering_order_status_change, ordering_order_line, ordering_order_delivery_vat, ordering_order, payment_payment, inventory_reservation_line, inventory_reservation, inventory_stock_movement, inventory_stock_item');
    }

    /**
     * @return array<string, string>
     */
    private function checksums(): array
    {
        $sums = [];
        foreach (self::TABLES as $table => $order) {
            $row = isset(self::RANDOM_COLUMNS[$table]) ? '(to_jsonb(t) - '.implode(' - ', array_map(static fn (string $column): string => "'".$column."'", self::RANDOM_COLUMNS[$table])).')::text' : 't::text';
            $sums[$table] = Sql::string($this->control->fetchOne(\sprintf("SELECT coalesce(md5(string_agg(%s, '|' ORDER BY %s)), 'empty') FROM %s t", $row, $order, $table)));
        }

        return $sums;
    }
}
