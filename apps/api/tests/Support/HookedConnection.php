<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * A connection (`wrapperClass` of DriverManager) that runs a closure of the test right after the n-th `fetchOne`, so a
 * test can change the data on another connection exactly between two statements of the code under test.
 */
final class HookedConnection extends Connection
{
    /** @var array<int, \Closure(): void> closure by the number of the `fetchOne` call (1 is the first) it follows */
    public array $afterFetch = [];
    public int $fetches = 0;

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        $value = parent::fetchOne($query, $params, $types);
        ++$this->fetches;
        if (isset($this->afterFetch[$this->fetches])) {
            ($this->afterFetch[$this->fetches])();
        }

        return $value;
    }
}
