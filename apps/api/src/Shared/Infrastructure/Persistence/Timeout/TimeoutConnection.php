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
        $this->apply();

        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        $this->apply();

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        if (!self::endsATransaction($sql)) {
            $this->apply();
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

    private function apply(): void
    {
        $limits = $this->timeouts->current();
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
