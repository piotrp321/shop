<?php

declare(strict_types=1);

namespace App\DemoData\Copy;

use Doctrine\DBAL\Connection;

/**
 * Loads rows with COPY FROM STDIN through the native connection of DBAL (Pdo\Pgsql::copyFromArray), in batches, into
 * tables that keep their indexes. Runs in the transaction of the caller, so a failed batch takes its part with it.
 */
final readonly class CopyWriter
{
    public const int BATCH_SIZE = 5_000;
    /** The NULL marker as pdo_pgsql wants it: it escapes the backslashes of the argument itself, so the data has `\N`. */
    private const string NULL_AS_ARGUMENT = '\\\\N';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * The session of a load does not wait for the WAL to be flushed at every commit (the data is rebuilt by a new run
     * when the server crashes). The setting follows the transaction it is made in.
     */
    public function relaxDurability(): void
    {
        $this->connection->executeStatement('SET synchronous_commit = off');
    }

    /**
     * @param non-empty-list<string> $columns
     * @param iterable<list<mixed>>  $rows    values in the order of the columns (see CopyRow)
     *
     * @return int the number of rows written
     */
    public function copy(string $table, array $columns, iterable $rows, int $batchSize = self::BATCH_SIZE): int
    {
        $native = $this->connection->getNativeConnection();
        if (!$native instanceof \Pdo\Pgsql) {
            throw new \RuntimeException('COPY needs the native connection of pdo_pgsql.');
        }

        $written = 0;
        $batch = [];
        foreach ($rows as $row) {
            $batch[] = CopyRow::line($row);
            if (\count($batch) >= $batchSize) {
                $written += $this->flush($native, $table, $columns, $batch);
                $batch = [];
            }
        }
        if ([] !== $batch) {
            $written += $this->flush($native, $table, $columns, $batch);
        }

        return $written;
    }

    /**
     * @param list<string> $columns
     * @param list<string> $batch
     */
    private function flush(\Pdo\Pgsql $native, string $table, array $columns, array $batch): int
    {
        if (!$native->copyFromArray($table, $batch, "\t", self::NULL_AS_ARGUMENT, implode(',', $columns))) {
            throw new \RuntimeException(\sprintf('COPY into %s failed.', $table));
        }

        return \count($batch);
    }
}
