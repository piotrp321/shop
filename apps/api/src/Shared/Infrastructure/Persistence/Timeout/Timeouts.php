<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Timeout;

/**
 * The limits of the session of a database connection, in seconds, 0 for none: how long a statement may run
 * (`statement_timeout`; it ends a wait for a lock too), how long a transaction may stay open without a statement
 * (`idle_in_transaction_session_timeout`; the connection is closed and its locks released) and how long a statement
 * may wait for a lock (`lock_timeout`).
 */
final readonly class Timeouts
{
    public function __construct(
        public int $statement,
        public int $idleInTransaction,
        public int $lock,
    ) {
    }

    public function withoutLockTimeout(): self
    {
        return new self($this->statement, $this->idleInTransaction, 0);
    }

    /**
     * The statements that set the limits on a session; the values are numbers of milliseconds, nothing of it comes from outside.
     */
    public function toSql(): string
    {
        return \sprintf(
            'SET statement_timeout = %d; SET idle_in_transaction_session_timeout = %d; SET lock_timeout = %d',
            $this->statement * 1000,
            $this->idleInTransaction * 1000,
            $this->lock * 1000,
        );
    }
}
