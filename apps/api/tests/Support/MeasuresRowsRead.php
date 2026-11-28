<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;

/**
 * Measures what a source reads from catalog_product: the rows returned by a scan node plus the rows it removed by the filter
 * and the recheck, times the loops, of the plans (EXPLAIN ANALYZE) of the statements that $recording (a RecordingConnection)
 * recorded, each planned under the settings the source had sent before it. The using class has $connection (the connection of
 * the test) and $recording.
 */
trait MeasuresRowsRead
{
    /**
     * The rows read from catalog_product by all the statements recorded since the last reset.
     */
    private function rowsRead(): int
    {
        $total = 0.0;
        foreach ($this->analyzedPlans() as $plan) {
            $total += self::rowsReadBy($plan);
        }

        return (int) ceil($total);
    }

    /**
     * The entries (tuple ids) that the bitmap index scans of the recorded statements read: the size of the lists of the GIN index.
     */
    private function bitmapEntriesRead(): int
    {
        $total = 0.0;
        foreach ($this->analyzedPlans() as $plan) {
            $total += self::bitmapEntriesOf($plan);
        }

        return (int) ceil($total);
    }

    /**
     * @param array<mixed> $node
     */
    private static function bitmapEntriesOf(array $node): float
    {
        $read = 'Bitmap Index Scan' === ($node['Node Type'] ?? null) ? self::number($node['Actual Rows'] ?? 0) * self::number($node['Actual Loops'] ?? 1) : 0.0;
        $children = $node['Plans'] ?? [];
        if (\is_array($children)) {
            foreach ($children as $child) {
                if (\is_array($child)) {
                    $read += self::bitmapEntriesOf($child);
                }
            }
        }

        return $read;
    }

    /**
     * @param array<mixed> $node
     */
    private static function rowsReadBy(array $node): float
    {
        $read = 0.0;
        $type = \is_string($node['Node Type'] ?? null) ? $node['Node Type'] : '';
        if ('catalog_product' === ($node['Relation Name'] ?? null) && str_contains($type, 'Scan') && !str_contains($type, 'Bitmap Index')) {
            $perLoop = self::number($node['Actual Rows'] ?? 0) + self::number($node['Rows Removed by Filter'] ?? 0) + self::number($node['Rows Removed by Index Recheck'] ?? 0);
            $read += $perLoop * self::number($node['Actual Loops'] ?? 1);
        }
        $children = $node['Plans'] ?? [];
        if (\is_array($children)) {
            foreach ($children as $child) {
                if (\is_array($child)) {
                    $read += self::rowsReadBy($child);
                }
            }
        }

        return $read;
    }

    private static function number(mixed $value): float
    {
        return \is_int($value) || \is_float($value) ? (float) $value : 0.0;
    }

    /**
     * @return list<array<mixed>> the plan trees (EXPLAIN ANALYZE) of the recorded statements
     */
    private function analyzedPlans(): array
    {
        $plans = [];
        foreach ($this->recording->queries as $i => [$sql, $params, $types]) {
            $json = $this->explained('EXPLAIN (ANALYZE, FORMAT JSON) ', $i, $sql, $params, $types)[0];
            \assert(\is_string($json));
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded) && \is_array($decoded[0] ?? null) && \is_array($decoded[0]['Plan'] ?? null));
            $plans[] = $decoded[0]['Plan'];
        }

        return $plans;
    }

    /**
     * The plan of the recorded query number $i, planned under the settings the source had sent before it in its transaction (a
     * savepoint rolled back afterwards, like the source's own), whatever they were; nothing is added.
     *
     * @param array<int<0, max>|string, mixed>                                        $params
     * @param array<int<0, max>|string, ArrayParameterType|ParameterType|Type|string> $types
     *
     * @return list<mixed> the first column of the rows of the plan
     */
    private function explained(string $explain, int $i, string $sql, array $params, array $types): array
    {
        $before = $this->recording->statementsOfQueries[$i];
        if ([] !== $before) {
            $this->connection->beginTransaction();
            foreach ($before as [$statement, $statementParams, $statementTypes]) {
                $this->connection->executeStatement($statement, $statementParams, $statementTypes);
            }
        }
        try {
            return $this->connection->executeQuery($explain.$sql, $params, $types)->fetchFirstColumn();
        } finally {
            if ([] !== $before) {
                $this->connection->rollBack();
            }
        }
    }

    /**
     * The plans of the recorded statements as text, for the message of a failure and for the checks of the indexes.
     */
    private function plans(): string
    {
        $text = [];
        foreach ($this->recording->queries as $i => [$sql, $params, $types]) {
            $lines = $this->explained('EXPLAIN (ANALYZE) ', $i, $sql, $params, $types);
            $text[] = $sql."\n".implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $lines));
        }

        return implode("\n----\n", $text);
    }
}
