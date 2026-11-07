<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * The current time, to the microsecond: the version of a search document is the moment its read started.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
