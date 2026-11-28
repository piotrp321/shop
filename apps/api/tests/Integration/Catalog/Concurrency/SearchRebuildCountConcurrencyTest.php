<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Concurrency;

use App\Kernel;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use App\Tests\Support\Concurrency\CountSearchRebuildBatch;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Criterion 5 of ASYNC-INDEXING: the counts of the last batches of a run at the same moment, each a process of its own
 * on its own connection (the `test_messaging` kernel, so the finalization is a row of the Doctrine transport
 * indexing_finalize). The UPDATE of the run locks its row, so exactly one sees the run complete and writes one
 * finalization; two deliveries of the same batch count it once. Every committed row is deleted after the test.
 */
final class SearchRebuildCountConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;

    private Connection $control;
    /** @var list<string> */
    private array $runs = [];

    protected function setUp(): void
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
        $messaging = new Kernel('test_messaging', false);
        $messaging->boot();
        $messaging->shutdown();
        $this->control->executeStatement('DELETE FROM catalog_search_rebuild_run WHERE finished_at IS NULL');
    }

    protected function tearDown(): void
    {
        foreach ($this->runs as $runId) {
            $this->control->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'indexing_finalize' AND position(? IN body) > 0", [$runId]);
            $this->control->executeStatement('DELETE FROM catalog_search_rebuild_run WHERE id = ?', [$runId]);
        }
        $this->control->close();
        parent::tearDown();
    }

    public function testTheCountsOfTheLastTwoBatchesAtOnceFinalizeTheRunOnce(): void
    {
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $runId = $this->runningRun(total: 3, done: [0]);

            $outcomes = ConcurrentCommands::run($this->control, [new CountSearchRebuildBatch($runId, 1, 10), new CountSearchRebuildBatch($runId, 2, 10)], 'test_messaging');

            $message = 'Round '.$round.': '.json_encode($outcomes, \JSON_THROW_ON_ERROR);
            self::assertSame([Outcome::SUCCESS, Outcome::SUCCESS], array_map(static fn (Outcome $o): string => $o->kind, $outcomes), $message);
            self::assertSame(['finalizing', 3, 30], $this->state($runId), $message);
            self::assertSame(1, $this->finalizations($runId), $message);
        }
    }

    public function testTwoDeliveriesOfTheLastBatchAtOnceCountItOnceAndFinalizeOnce(): void
    {
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $runId = $this->runningRun(total: 2, done: [0]);

            $outcomes = ConcurrentCommands::run($this->control, [new CountSearchRebuildBatch($runId, 1, 10), new CountSearchRebuildBatch($runId, 1, 10)], 'test_messaging');

            $message = 'Round '.$round.': '.json_encode($outcomes, \JSON_THROW_ON_ERROR);
            self::assertSame(['finalizing', 2, 20], $this->state($runId), $message);
            self::assertSame(1, $this->finalizations($runId), $message);
        }
    }

    /**
     * @param list<int> $done
     */
    private function runningRun(int $total, array $done): string
    {
        $this->control->executeStatement('DELETE FROM catalog_search_rebuild_run WHERE finished_at IS NULL');
        foreach ($this->runs as $previous) {
            $this->control->executeStatement("UPDATE catalog_search_rebuild_run SET status = 'succeeded', finished_at = now() WHERE id = ?", [$previous]);
        }
        $runId = Uuid::v7()->toRfc4122();
        $this->control->executeStatement(
            "INSERT INTO catalog_search_rebuild_run (id, document_type, status, target_index, previous_index, batch_size, total_batches, done_batches, documents, started_at, heartbeat_at)
             VALUES (?, 'product', 'running', 'test_products_new', 'test_products_old', 10, ?, ?, ?, now(), now())",
            [$runId, $total, \count($done), 10 * \count($done)],
        );
        // Every batch registered as the producer does (CountSearchRebuildBatchHandler counts the ids ["batch-<n>"]).
        for ($batch = 0; $batch < $total; ++$batch) {
            $this->control->executeStatement(
                'INSERT INTO catalog_search_rebuild_batch (run_id, batch, ids_hash, documents) VALUES (?, ?, ?, ?)',
                [$runId, $batch, hash('sha256', 'batch-'.$batch), \in_array($batch, $done, true) ? 10 : null],
            );
        }
        $this->runs[] = $runId;

        return $runId;
    }

    /**
     * @return array{mixed, mixed, mixed}
     */
    private function state(string $runId): array
    {
        $row = $this->control->fetchAssociative('SELECT status, done_batches, documents FROM catalog_search_rebuild_run WHERE id = ?', [$runId]);
        self::assertIsArray($row);

        return [$row['status'], $row['done_batches'], $row['documents']];
    }

    private function finalizations(string $runId): int
    {
        $count = $this->control->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'indexing_finalize' AND position(? IN body) > 0", [$runId]);
        self::assertIsInt($count);

        return $count;
    }
}
