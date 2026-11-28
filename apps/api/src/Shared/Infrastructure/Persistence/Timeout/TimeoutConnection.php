<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Timeout;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Before a statement (and before a transaction begins), sets on the session the limits that
 * {@see DatabaseTimeouts} says now, unless the session already has them. Nothing is sent while the limits do not
 * change, and nothing when the connection is not used.
 *
 * A statement that runs CONCURRENTLY (CREATE and DROP INDEX, REINDEX, DETACH PARTITION) is sent with no lock timeout and
 * the next statement sets it again: it waits for every transaction that started before it (a query of the panel, a
 * worker, the generator, a dump) and blocks nobody while it waits, so a lock timeout would only end it with an INVALID
 * index, and the retry of the migration the same way. Such a statement cannot run in a transaction, which is why the
 * migrations that have it are not transactional.
 *
 * A setting made inside a transaction that is rolled back goes with it, so a rollback makes the connection set the
 * limits again. The statements that finish a failed transaction (ROLLBACK TO SAVEPOINT) are sent as they are: the
 * session accepts nothing else in that state.
 */
final class TimeoutConnection extends AbstractConnectionMiddleware
{
    private ?Timeouts $applied = null;

    public function __construct(private readonly Connection $connection, private readonly DatabaseTimeouts $timeouts)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $this->apply($sql);

        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        $this->apply($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        if (!self::endsATransaction($sql)) {
            $this->apply($sql);
        }

        return parent::exec($sql);
    }

    public function beginTransaction(): void
    {
        $this->apply();
        parent::beginTransaction();
    }

    public function rollBack(): void
    {
        $this->applied = null;
        parent::rollBack();
    }

    /**
     * Whether the statement runs CONCURRENTLY: CREATE, DROP, REINDEX or ALTER with the keyword near its beginning (the
     * word inside a value or a name does not count).
     */
    public static function isConcurrentDdl(string $sql): bool
    {
        return 1 === preg_match('/^\s*(CREATE|DROP|REINDEX|ALTER)\b[^;\'"]{0,120}?\bCONCURRENTLY\b/is', $sql);
    }

    private function apply(string $sql = ''): void
    {
        $limits = $this->timeouts->current();
        if (self::isConcurrentDdl($sql)) {
            $limits = $limits->withoutLockTimeout();
        }
        if ($limits == $this->applied) {
            return;
        }
        $this->applied = null;
        $this->connection->exec($limits->toSql());
        $this->applied = $limits;
    }

    private static function endsATransaction(string $sql): bool
    {
        return 1 === preg_match('/^\s*(ROLLBACK|RELEASE|SAVEPOINT)\b/i', $sql);
    }
}
