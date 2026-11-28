<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * The state of an asynchronous rebuild run as last committed.
 */
final readonly class SearchRebuildRun
{
    public function __construct(
        public string $id,
        public string $documentType,
        public SearchRebuildStatus $status,
        public string $targetIndex,
        public string $previousIndex,
        public int $batchSize,
        public ?int $totalBatches,
        public int $doneBatches,
        public int $documents,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $heartbeatAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?string $failure,
    ) {
    }

    /**
     * Whether it was aborted or failed while it took batches (never all counted): its new index is deleted, also when a
     * late write of a batch created it again.
     */
    public function endedBeforeFinalization(): bool
    {
        return (SearchRebuildStatus::Aborted === $this->status || SearchRebuildStatus::Failed === $this->status)
            && (null === $this->totalBatches || $this->doneBatches < $this->totalBatches);
    }

    /**
     * Whether nothing moved it (no batch counted, no beat of the producer, no stage) for longer than $limitSeconds.
     */
    public function isStalled(\DateTimeImmutable $now, int $limitSeconds): bool
    {
        return $now->getTimestamp() - $this->heartbeatAt->getTimestamp() > $limitSeconds;
    }
}
