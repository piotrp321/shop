<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Command\CategoryReindexRun;
use App\Catalog\Application\Port\CategoryReindexRuns;
use App\Catalog\Domain\Category\CategoryId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The runs of the reindexing of category subtrees by DBAL on the connection of the transaction of command.bus (the
 * record only defines the schema): the lock is an explicit SELECT … FOR UPDATE, which always reads the committed row,
 * so the second of two concurrent deliveries of a batch sees the number the first committed.
 */
final readonly class DbalCategoryReindexRuns implements CategoryReindexRuns
{
    public function __construct(private Connection $connection)
    {
    }

    public function start(CategoryId $categoryId): string
    {
        $runId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            'INSERT INTO catalog_category_reindex (id, category_id, cursor, next_batch, finished_at) VALUES (?, ?, NULL, 0, NULL)',
            [$runId, $categoryId->toString()],
        );

        return $runId;
    }

    public function lock(string $runId): ?CategoryReindexRun
    {
        // Outside a transaction the lock would end with its own statement and guard nothing.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('The lock of a reindexing run needs the transaction of its batch.');
        }
        $row = $this->connection->fetchAssociative(
            'SELECT id, category_id, cursor, next_batch, finished_at IS NOT NULL AS finished FROM catalog_category_reindex WHERE id = ? FOR UPDATE',
            [$runId],
        );
        if (false === $row) {
            return null;
        }
        \assert(\is_string($row['id']) && \is_string($row['category_id']) && (null === $row['cursor'] || \is_string($row['cursor'])));
        \assert(\is_int($row['next_batch']) && \is_bool($row['finished']));

        return new CategoryReindexRun($row['id'], $row['category_id'], $row['cursor'], $row['next_batch'], $row['finished']);
    }

    public function advance(string $runId, string $cursor, int $nextBatch): void
    {
        $this->connection->executeStatement(
            'UPDATE catalog_category_reindex SET cursor = ?, next_batch = ? WHERE id = ?',
            [strtolower($cursor), $nextBatch, $runId],
        );
    }

    public function finish(string $runId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE catalog_category_reindex SET finished_at = ? WHERE id = ?',
            [$at->format('Y-m-d H:i:sP'), $runId],
        );
    }
}
