<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Command\FinalizeSearchRebuild;
use App\Catalog\Application\Port\SearchRebuildRuns;
use App\Catalog\Application\Search\SearchRebuildRun;
use App\Catalog\Application\Search\SearchRebuildStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The runs of the asynchronous rebuild by DBAL (the records only define the schema; docs/features/async-indexing/spec.md,
 * "Rozliczenie paczki"). Every change of a run is an UPDATE conditional on its status, which also locks its row to the
 * end of the transaction, so concurrent counts of batches go one after another and exactly one sees the run complete.
 * The finalization is sent on indexing.bus inside the same transaction: its transport `indexing_finalize` writes a row
 * of messenger_messages on this connection, so the message exists exactly when the move to `finalizing` is committed.
 */
final readonly class DbalSearchRebuildRuns implements SearchRebuildRuns
{
    private const string COLUMNS = 'id, document_type, status, target_index, previous_index, batch_size, total_batches, done_batches, documents, started_at, heartbeat_at, finished_at, failure';

    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'indexing.bus')]
        private MessageBusInterface $indexingBus,
    ) {
    }

    public function start(string $documentType, string $targetIndex, string $previousIndex, int $batchSize, \DateTimeImmutable $now): string
    {
        $runId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO catalog_search_rebuild_run (id, document_type, status, target_index, previous_index, batch_size, total_batches, done_batches, documents, started_at, heartbeat_at)
             VALUES (?, ?, 'producing', ?, ?, ?, NULL, 0, 0, ?, ?)",
            [$runId, $documentType, $targetIndex, $previousIndex, $batchSize, self::time($now), self::time($now)],
        );

        return $runId;
    }

    public function get(string $runId): ?SearchRebuildRun
    {
        if (!Uuid::isValid($runId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM catalog_search_rebuild_run WHERE id = ?', [$runId]);

        return false === $row ? null : self::run($row);
    }

    public function active(string $documentType): ?SearchRebuildRun
    {
        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM catalog_search_rebuild_run WHERE document_type = ? AND finished_at IS NULL', [$documentType]);

        return false === $row ? null : self::run($row);
    }

    public function beat(string $runId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            "UPDATE catalog_search_rebuild_run SET heartbeat_at = ? WHERE id = ? AND status = 'producing'",
            [self::time($now), $runId],
        );
    }

    public function countBatch(string $runId, int $batch, int $documents, \DateTimeImmutable $now): ?SearchRebuildRun
    {
        $this->connection->beginTransaction();
        try {
            // First the run (its row locked to the commit), then the batch: a repeated batch rolls the count back.
            $row = $this->connection->fetchAssociative(
                "UPDATE catalog_search_rebuild_run SET done_batches = done_batches + 1, documents = documents + ?, heartbeat_at = ?
                 WHERE id = ? AND status IN ('producing', 'running') RETURNING ".self::COLUMNS,
                [$documents, self::time($now), $runId],
            );
            $counted = false !== $row && 1 === $this->connection->executeStatement(
                'INSERT INTO catalog_search_rebuild_batch (run_id, batch, documents) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                [$runId, $batch, $documents],
            );
            if (!$counted) {
                $this->connection->rollBack();

                return null;
            }
            $run = self::run($row);
            if (SearchRebuildStatus::Running === $run->status && $run->doneBatches === $run->totalBatches) {
                $this->connection->executeStatement("UPDATE catalog_search_rebuild_run SET status = 'finalizing' WHERE id = ? AND status = 'running'", [$runId]);
                $this->indexingBus->dispatch(new FinalizeSearchRebuild($runId));
                $run = $this->get($runId);
                \assert(null !== $run);
            }
            $this->connection->commit();

            return $run;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    public function finishProduction(string $runId, int $totalBatches, \DateTimeImmutable $now): bool
    {
        return $this->connection->transactional(function () use ($runId, $totalBatches, $now): bool {
            $status = $this->connection->fetchOne(
                "UPDATE catalog_search_rebuild_run
                 SET total_batches = ?, heartbeat_at = ?, status = CASE WHEN done_batches = ? THEN 'finalizing' ELSE 'running' END
                 WHERE id = ? AND status = 'producing' RETURNING status",
                [$totalBatches, self::time($now), $totalBatches, $runId],
            );
            if (false === $status) {
                return false;
            }
            if (SearchRebuildStatus::Finalizing->value === $status) {
                $this->indexingBus->dispatch(new FinalizeSearchRebuild($runId));
            }

            return true;
        });
    }

    public function move(string $runId, array $from, SearchRebuildStatus $to, \DateTimeImmutable $now, ?string $failure = null): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE catalog_search_rebuild_run
             SET status = :to, heartbeat_at = :now, finished_at = CASE WHEN CAST(:final AS boolean) THEN CAST(:now AS timestamptz) ELSE finished_at END,
                 failure = COALESCE(failure, :failure)
             WHERE id = :id AND status IN (:from)',
            [
                'to' => $to->value,
                'now' => self::time($now),
                'final' => $to->isFinal() ? 'true' : 'false',
                'failure' => null === $failure ? null : mb_substr($failure, 0, 2000),
                'id' => $runId,
                'from' => array_map(static fn (SearchRebuildStatus $status): string => $status->value, $from),
            ],
            ['from' => ArrayParameterType::STRING],
        );
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:sP');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function run(array $row): SearchRebuildRun
    {
        \assert(\is_string($row['id']) && \is_string($row['document_type']) && \is_string($row['status']));
        \assert(\is_string($row['target_index']) && \is_string($row['previous_index']) && \is_int($row['batch_size']));
        \assert((null === $row['total_batches'] || \is_int($row['total_batches'])) && \is_int($row['done_batches']) && \is_int($row['documents']));
        \assert(\is_string($row['started_at']) && \is_string($row['heartbeat_at']) && (null === $row['finished_at'] || \is_string($row['finished_at'])));
        \assert(null === $row['failure'] || \is_string($row['failure']));

        return new SearchRebuildRun(
            $row['id'],
            $row['document_type'],
            SearchRebuildStatus::from($row['status']),
            $row['target_index'],
            $row['previous_index'],
            $row['batch_size'],
            $row['total_batches'],
            $row['done_batches'],
            $row['documents'],
            new \DateTimeImmutable($row['started_at']),
            new \DateTimeImmutable($row['heartbeat_at']),
            null === $row['finished_at'] ? null : new \DateTimeImmutable($row['finished_at']),
            $row['failure'],
        );
    }
}
