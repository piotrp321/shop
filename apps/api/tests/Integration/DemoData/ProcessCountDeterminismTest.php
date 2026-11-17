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
 * The rows of the catalog steps do not depend on the number of processes: bin/console processes (APP_ENV=test) write
 * the parts of the products with 1, 3 and 4 workers, and the checksum of every table is the same. The processes commit,
 * so the test reads and cleans the tables through a connection of its own, outside the transaction of the test; it
 * empties the tables before and after every run. Its own group, so a run that cannot start processes can leave it out.
 */
#[Group('demo-data-processes')]
final class ProcessCountDeterminismTest extends KernelTestCase
{
    private const string ANCHOR = '2026-11-17T00:00:00Z';
    private const array TABLES = ['catalog_category', 'catalog_product', 'catalog_product_variant', 'catalog_product_category'];

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

    public function testTheCatalogRowsAreTheSameWithOneThreeAndFourProcesses(): void
    {
        $sums = [];
        foreach ([1, 3, 4] as $processes) {
            $this->generate($processes);
            $sums[$processes] = $this->checksums();
            self::assertSame(2_000, Sql::int($this->control->fetchOne('SELECT count(*) FROM catalog_product')), $processes.' processes');
            $this->empty();
        }

        self::assertNotContains('empty', $sums[1]);
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
        foreach ($running as $worker => [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
            $code = proc_close($process);
            self::assertSame(0, $code, \sprintf('worker %d of %d: %s', $worker, $processes, $output));
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
        $this->control->executeStatement('TRUNCATE catalog_product_category, catalog_product_variant, catalog_product, catalog_category_redirect, catalog_category');
    }

    /**
     * @return array<string, string>
     */
    private function checksums(): array
    {
        $sums = [];
        foreach (self::TABLES as $table) {
            $order = 'catalog_product_category' === $table ? 't.product_id, t.category_id' : 't.id';
            $sums[$table] = Sql::string($this->control->fetchOne(\sprintf("SELECT coalesce(md5(string_agg(t::text, '|' ORDER BY %s)), 'empty') FROM %s t", $order, $table)));
        }

        return $sums;
    }
}
