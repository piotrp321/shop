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

    /**
     * For each of $queries, the statements (executeStatement) that were sent before it in the transactions this connection
     * had open at that moment, oldest first: the settings a query was read under, as the code sent them.
     *
     * @var list<list<array{string, array<int<0, max>|string, mixed>, array<int<0, max>|string, ArrayParameterType|ParameterType|Type|string>}>>
     */
    public array $statementsOfQueries = [];

    /** @var list<string> `begin`, `commit` and `rollback` in the order this connection did them */
    public array $transactionEvents = [];

    /** Called with the SQL of each query before it runs; it may inspect the session or throw. */
    public ?\Closure $beforeQuery = null;

    /** @var list<list<array{string, array<int<0, max>|string, mixed>, array<int<0, max>|string, ArrayParameterType|ParameterType|Type|string>}>> */
    private array $scopes = [];

    public static function like(Connection $connection): self
    {
        return new self($connection->getParams(), $connection->getDriver(), $connection->getConfiguration());
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $this->queries[] = [$sql, $params, $types];
        $this->statementsOfQueries[] = array_merge(...$this->scopes);
        if (null !== $this->beforeQuery) {
            ($this->beforeQuery)($sql);
        }

        return parent::executeQuery($sql, $params, $types, $qcp);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if ([] !== $this->scopes) {
            $this->scopes[array_key_last($this->scopes)][] = [$sql, $params, $types];
        }

        return parent::executeStatement($sql, $params, $types);
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->scopes[] = [];
        $this->transactionEvents[] = 'begin';
    }

    public function commit(): void
    {
        $this->transactionEvents[] = 'commit';
        array_pop($this->scopes);
        parent::commit();
    }

    public function rollBack(): void
    {
        $this->transactionEvents[] = 'rollback';
        array_pop($this->scopes);
        parent::rollBack();
    }

    /**
     * Forgets everything recorded so far (the queries with what was sent before each of them, and the transactions).
     */
    public function forget(): void
    {
        $this->queries = [];
        $this->statementsOfQueries = [];
        $this->transactionEvents = [];
    }

    /**
     * The plan of the last recorded query, one line per row, planned with sequential scans and explicit sorts made as
     * unattractive as the planner allows: what is left shows whether an index delivers the order. With
     * $steerAwayFromScans false the settings stay as they are, so the plan is the one the planner picks on its own
     * statistics.
     */
    public function planOfTheLastQuery(Connection $database, bool $steerAwayFromScans = true): string
    {
        if ([] === $this->queries) {
            throw new \LogicException('No query was recorded.');
        }
        [$sql, $params, $types] = $this->queries[array_key_last($this->queries)];
        if ($steerAwayFromScans) {
            $database->executeStatement('SET LOCAL enable_seqscan = off');
            $database->executeStatement('SET LOCAL enable_sort = off');
        }
        $lines = $database->executeQuery('EXPLAIN '.$sql, $params, $types)->fetchFirstColumn();

        return implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $lines));
    }
}
