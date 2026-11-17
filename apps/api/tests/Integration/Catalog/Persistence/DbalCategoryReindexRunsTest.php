<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Application\Command\CategoryReindexRun;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalCategoryReindexRuns;
use App\Tests\Integration\Pricing\Promotion\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

/**
 * The runs of the reindexing of category subtrees on PostgreSQL (catalog_category_reindex). Both connections are the
 * test's own (TestDatabase), so each transaction and each row lock is real; every transaction is rolled back.
 */
final class DbalCategoryReindexRunsTest extends TestCase
{
    private const string CATEGORY = 'C0000000-0000-4000-8000-0000000000AA';

    private Connection $connection;
    private Connection $other;
    /** @var list<string> */
    private array $committed = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connect();
        $this->other = TestDatabase::connect();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        foreach ([$this->connection, $this->other] as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        foreach ($this->committed as $runId) {
            $this->other->executeStatement('DELETE FROM catalog_category_reindex WHERE id = ?', [$runId]);
        }
        $this->connection->close();
        $this->other->close();
        parent::tearDown();
    }

    public function testANewRunExpectsTheFirstBatchWithoutACursor(): void
    {
        $runs = new DbalCategoryReindexRuns($this->connection);

        $runId = $runs->start(CategoryId::fromString(self::CATEGORY));

        self::assertEquals(new CategoryReindexRun($runId, strtolower(self::CATEGORY), null, 0, false), $runs->lock($runId));
        self::assertSame(
            ['category_id' => strtolower(self::CATEGORY), 'cursor' => null, 'next_batch' => 0, 'finished_at' => null],
            $this->connection->fetchAssociative('SELECT category_id, cursor, next_batch, finished_at FROM catalog_category_reindex WHERE id = ?', [$runId]),
        );
    }

    public function testAdvanceAndFinishAreReadBackUnderTheLock(): void
    {
        $runs = new DbalCategoryReindexRuns($this->connection);
        $runId = $runs->start(CategoryId::fromString(self::CATEGORY));

        $runs->advance($runId, 'A0000000-0000-4000-8000-000000000001', 1);
        self::assertEquals(new CategoryReindexRun($runId, strtolower(self::CATEGORY), 'a0000000-0000-4000-8000-000000000001', 1, false), $runs->lock($runId));

        $runs->finish($runId, new \DateTimeImmutable('2026-11-15T12:00:00.5+00:00'));
        self::assertEquals(new CategoryReindexRun($runId, strtolower(self::CATEGORY), 'a0000000-0000-4000-8000-000000000001', 1, true), $runs->lock($runId));
        // To the second, like every moment of the schema.
        self::assertSame('2026-11-15 12:00:00+00', $this->connection->fetchOne('SELECT finished_at FROM catalog_category_reindex WHERE id = ?', [$runId]));
    }

    public function testAnUnknownRunIsNull(): void
    {
        self::assertNull(new DbalCategoryReindexRuns($this->connection)->lock('00000000-0000-4000-8000-000000000999'));
    }

    public function testTheLockKeepsOutAnotherLockOfTheRunUntilTheTransactionEnds(): void
    {
        $runId = $this->committedRun();
        $this->connection->beginTransaction();
        new DbalCategoryReindexRuns($this->connection)->lock($runId);

        $this->other->beginTransaction();
        $this->other->executeStatement("SET LOCAL lock_timeout = '100ms'");
        try {
            new DbalCategoryReindexRuns($this->other)->lock($runId);
            self::fail('The second lock was granted while the first transaction held the row.');
        } catch (DriverException $e) {
            self::assertSame('55P03', $e->getSQLState(), 'lock_not_available: the lock timed out');
        } finally {
            $this->other->rollBack();
        }

        $this->connection->rollBack();
        $this->other->beginTransaction();
        self::assertNotNull(new DbalCategoryReindexRuns($this->other)->lock($runId), 'granted once the first transaction ended');
    }

    public function testTheLockIsRefusedOutsideATransaction(): void
    {
        $this->expectException(\LogicException::class);

        new DbalCategoryReindexRuns($this->other)->lock('00000000-0000-4000-8000-000000000999');
    }

    /**
     * A run committed on the test's connection (outside the transaction of setUp), deleted after the test.
     */
    private function committedRun(): string
    {
        $this->connection->rollBack();
        $runId = new DbalCategoryReindexRuns($this->connection)->start(CategoryId::fromString(self::CATEGORY));
        $this->committed[] = $runId;

        return $runId;
    }
}
