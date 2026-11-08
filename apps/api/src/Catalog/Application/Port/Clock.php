<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * The current time, to the microsecond: the version of a search document is the moment its read started, and a
 * product write takes its time (first publication, updated_at, the occurrence of its events) from it.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
