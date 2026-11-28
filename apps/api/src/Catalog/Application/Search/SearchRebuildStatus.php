<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * The statuses of an asynchronous rebuild run (docs/features/async-indexing/spec.md, "Przebieg" and "Finalizacja"):
 * the producer sends the batches (producing), the workers count them (running), then the finalization goes through its
 * stages, each saved before the next starts, to one final status.
 */
enum SearchRebuildStatus: string
{
    case Producing = 'producing';
    case Running = 'running';
    case Finalizing = 'finalizing';
    case Verified = 'verified';
    case Activating = 'activating';
    case Activated = 'activated';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Aborted = 'aborted';

    public function isFinal(): bool
    {
        return \in_array($this, [self::Succeeded, self::Failed, self::Aborted], true);
    }

    /**
     * Whether batches are still written and counted.
     */
    public function takesBatches(): bool
    {
        return self::Producing === $this || self::Running === $this;
    }
}
