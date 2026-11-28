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
     * The statuses that are not final, in their order.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status): bool => !$status->isFinal()));
    }

    /**
     * Whether the run is in a stage of its finalization (it no longer takes batches and is not final).
     */
    public function finalizes(): bool
    {
        return !$this->isFinal() && !$this->takesBatches();
    }

    /**
     * Whether batches are still written and counted.
     */
    public function takesBatches(): bool
    {
        return self::Producing === $this || self::Running === $this;
    }
}
