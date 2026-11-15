<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Backfill;

use App\Shared\Infrastructure\Backfill\BackfillLog;
use App\Shared\Infrastructure\Backfill\BackfillStep;
use App\Shared\UI\Console\BackfillCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkApplication;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:backfill on PostgreSQL (shop_test, rolled back after each test): the steps run in their order, each completed
 * one is recorded in app_backfill and skipped by later runs, --force runs them all again, and a failing step is not
 * recorded and stops the run.
 */
final class BackfillCommandTest extends KernelTestCase
{
    private Connection $connection;
    /** @var \ArrayObject<int, string> the names of the steps run, in order */
    private \ArrayObject $runs;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM app_backfill');
        $this->runs = new \ArrayObject();
    }

    public function testRunsTheStepsInTheirOrderAndRecordsEach(): void
    {
        $tester = $this->tester([$this->step('first'), $this->step('second')]);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        self::assertSame(['first', 'second'], $this->runs());
        self::assertSame(['first', 'second'], $this->recorded());
        self::assertStringContainsString('first: report of first', $tester->getDisplay());
    }

    public function testASecondRunSkipsTheCompletedStepsAndRunsANewOne(): void
    {
        $this->tester([$this->step('first'), $this->step('second')])->execute([]);
        $completedAt = $this->completedAt('first');
        $this->runs->exchangeArray([]);

        $tester = $this->tester([$this->step('first'), $this->step('second'), $this->step('third')]);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(['third'], $this->runs());
        self::assertSame(['first', 'second', 'third'], $this->recorded());
        self::assertSame($completedAt, $this->completedAt('first'));
        self::assertStringContainsString('first: skipped', $tester->getDisplay());
    }

    public function testForceRunsTheCompletedStepsAgain(): void
    {
        $this->tester([$this->step('first'), $this->step('second')])->execute([]);
        $this->connection->executeStatement("UPDATE app_backfill SET completed_at = '2026-01-01 00:00:00+00'");
        $this->runs->exchangeArray([]);

        $tester = $this->tester([$this->step('first'), $this->step('second')]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]), $tester->getDisplay());
        self::assertSame(['first', 'second'], $this->runs());
        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM app_backfill WHERE completed_at = '2026-01-01 00:00:00+00'"));
    }

    public function testAFailingStepIsNotRecordedAndStopsTheRunAndTheNextRunStartsWithIt(): void
    {
        $tester = $this->tester([$this->step('first'), $this->step('broken', fails: true), $this->step('third')]);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(['first', 'broken'], $this->runs());
        self::assertSame(['first'], $this->recorded());
        self::assertStringContainsString('broken: failed: the database went away', $tester->getDisplay());
        $this->runs->exchangeArray([]);

        $tester = $this->tester([$this->step('first'), $this->step('broken'), $this->step('third')]);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(['broken', 'third'], $this->runs());
        self::assertSame(['broken', 'first', 'third'], $this->recorded());
    }

    public function testTwoStepsOfOneNameRunNothing(): void
    {
        $tester = $this->tester([$this->step('first'), $this->step('first')]);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame([], $this->runs());
        self::assertSame([], $this->recorded());
        self::assertStringContainsString('"first"', $tester->getDisplay());
    }

    public function testTheRegisteredStepsRunInTheirOrderThroughTheKernel(): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new FrameworkApplication(self::$kernel)->find('app:backfill'));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        $first = $tester->getDisplay();
        // The stock records come first, the statistics of the products after them, then the hourly sales; every one
        // is recorded.
        self::assertSame(0, strpos($first, 'inventory:stock-records:sync: running'), $first);
        self::assertLessThan(strpos($first, 'catalog:analyze-products: running'), strpos($first, 'inventory:stock-records:sync: completed'));
        self::assertLessThan(strpos($first, 'ordering:read-models:rebuild:sales: running'), strpos($first, 'catalog:analyze-products: completed'));
        self::assertStringContainsString('ordering:read-models:rebuild:sales: sales: ', $first);
        self::assertEqualsCanonicalizing(['inventory:stock-records:sync', 'catalog:analyze-products', 'ordering:read-models:rebuild:sales'], $this->recorded());
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('inventory:stock-records:sync: skipped', $tester->getDisplay());
        self::assertStringContainsString('catalog:analyze-products: skipped', $tester->getDisplay());
        self::assertStringContainsString('ordering:read-models:rebuild:sales: skipped', $tester->getDisplay());
    }

    public function testMigratedSchemaMatchesTheMapping(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $updates = array_values(array_filter(
            new SchemaTool($entityManager)->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata()),
            static fn (string $sql): bool => str_contains($sql, 'app_backfill'),
        ));

        self::assertSame([], $updates);
    }

    private function step(string $name, bool $fails = false): BackfillStep
    {
        return new class($name, $fails, $this->runs) implements BackfillStep {
            /**
             * @param \ArrayObject<int, string> $runs
             */
            public function __construct(private readonly string $name, private readonly bool $fails, private readonly \ArrayObject $runs)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function run(\Closure $report): void
            {
                $this->runs->append($this->name);
                if ($this->fails) {
                    throw new \RuntimeException('the database went away');
                }
                $report('report of '.$this->name);
            }
        };
    }

    /**
     * @param list<BackfillStep> $steps
     */
    private function tester(array $steps): CommandTester
    {
        $application = new Application();
        $application->addCommand(new BackfillCommand($steps, new BackfillLog($this->connection), new NullLogger()));

        return new CommandTester($application->find('app:backfill'));
    }

    /**
     * @return list<string>
     */
    private function runs(): array
    {
        return array_values($this->runs->getArrayCopy());
    }

    /**
     * @return list<mixed>
     */
    private function recorded(): array
    {
        return $this->connection->fetchFirstColumn('SELECT name FROM app_backfill ORDER BY name');
    }

    private function completedAt(string $name): mixed
    {
        return $this->connection->fetchOne('SELECT completed_at FROM app_backfill WHERE name = ?', [$name]);
    }
}
