<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * How the start of an asynchronous rebuild ended: the run whose batches were all sent, or why there is none.
 */
final readonly class AsyncRebuildStart
{
    private function __construct(public ?string $runId, public ?string $failure)
    {
    }

    public static function produced(string $runId): self
    {
        return new self($runId, null);
    }

    public static function failed(string $failure, ?string $runId = null): self
    {
        return new self($runId, $failure);
    }
}
