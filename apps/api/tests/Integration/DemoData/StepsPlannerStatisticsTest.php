<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Copy\Sql;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rows the steps load stay uncommitted in the transaction of the test. A vacuum from another session (autovacuum,
 * after the rollback of an earlier test left dead rows) does not count rows of a transaction in progress: it sets
 * reltuples to 0 while the pages hold the rows of the test. The planner then expects no rows in the tables of the
 * catalog and plans the joins of the reads after the steps (catalog:reindex, the listings) as nested loops over whole
 * tables: in CI each test of GeneratedProductsOnTheRealPathsTest took about a minute instead of a second. The steps of
 * the trait end with ANALYZE in the transaction of the test, which counts its rows and keeps such a vacuum off the
 * tables until the rollback.
 */
final class StepsPlannerStatisticsTest extends KernelTestCase
{
    use RunsDemoDataSteps;

    private Connection $other;

    protected function setUp(): void
    {
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection()->getParams();
        $this->other = DriverManager::getConnection($params, new DbalConfiguration());
    }

    protected function tearDown(): void
    {
        $this->other->close();
        parent::tearDown();
    }

    public function testTheRowEstimatesOfTheCatalogTablesSurviveAVacuumOfAnotherSessionAfterTheCatalogSteps(): void
    {
        $this->catalogSteps();

        // What autovacuum does: it skips a table it cannot lock at once instead of waiting.
        $this->other->executeStatement('VACUUM (SKIP_LOCKED) '.implode(', ', self::CATALOG_TABLES));

        foreach (self::CATALOG_TABLES as $table) {
            $rows = $this->int($this->connection()->fetchOne('SELECT count(*) FROM '.$table));
            self::assertGreaterThan(0, $rows, $table);
            self::assertGreaterThanOrEqual(intdiv($rows, 2), $this->estimatedRows($table), \sprintf('The planner expects far fewer rows in %s than the %d of the test.', $table, $rows));
        }
    }

    private function estimatedRows(string $table): int
    {
        $plan = json_decode(Sql::string($this->connection()->fetchOne('EXPLAIN (FORMAT JSON) SELECT * FROM '.$table)), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        self::assertIsArray($plan[0] ?? null);
        self::assertIsArray($plan[0]['Plan'] ?? null);

        return Sql::int($plan[0]['Plan']['Plan Rows'] ?? null);
    }
}
