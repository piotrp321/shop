<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Type;

/**
 * A connection to the same database (and so to the same DAMA transaction) as the one it is made of that remembers the
 * queries a reader sends, with their values, so a test can ask the database for the plan of exactly that statement.
 */
final class RecordingConnection extends Connection
{
    /** @var list<array{string, array<int<0, max>|string, mixed>, array<int<0, max>|string, ArrayParameterType|ParameterType|Type|string>}> */
    public array $queries = [];

    public static function like(Connection $connection): self
    {
        return new self($connection->getParams(), $connection->getDriver(), $connection->getConfiguration());
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $this->queries[] = [$sql, $params, $types];

        return parent::executeQuery($sql, $params, $types, $qcp);
    }

    /**
     * The plan of the last recorded query, one line per row, planned with sequential scans and explicit sorts made as
     * unattractive as the planner allows: what is left shows whether an index delivers the order.
     */
    public function planOfTheLastQuery(Connection $database): string
    {
        if ([] === $this->queries) {
            throw new \LogicException('No query was recorded.');
        }
        [$sql, $params, $types] = $this->queries[array_key_last($this->queries)];
        $database->executeStatement('SET LOCAL enable_seqscan = off');
        $database->executeStatement('SET LOCAL enable_sort = off');
        $lines = $database->executeQuery('EXPLAIN '.$sql, $params, $types)->fetchFirstColumn();

        return implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $lines));
    }
}
