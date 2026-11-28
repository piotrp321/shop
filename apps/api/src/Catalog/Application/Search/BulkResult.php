<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * The result of every item of a bulk write, by document id.
 */
final readonly class BulkResult
{
    /**
     * @param list<string>          $indexed
     * @param list<string>          $stale             rejected because the index holds a newer version; correct in a rebuild,
     *                                                 where a single write through the rebuild alias was newer
     * @param array<string, string> $transientFailures id => reason; retrying later may succeed
     * @param array<string, string> $permanentFailures id => reason; retrying will not help
     */
    public function __construct(
        public array $indexed = [],
        public array $stale = [],
        public array $transientFailures = [],
        public array $permanentFailures = [],
    ) {
    }

    /**
     * Whether an item failed; a stale item is not a failure.
     */
    public function hasFailures(): bool
    {
        return [] !== $this->transientFailures || [] !== $this->permanentFailures;
    }

    public function allIndexed(): bool
    {
        return [] === $this->stale && [] === $this->transientFailures && [] === $this->permanentFailures;
    }

    /**
     * A short summary of the items that were not indexed, for logs and console output.
     */
    public function describeProblems(): string
    {
        $parts = [];
        if ([] !== $this->stale) {
            $parts[] = \sprintf('%d stale (first: %s)', \count($this->stale), $this->stale[0]);
        }
        foreach (['transient' => $this->transientFailures, 'permanent' => $this->permanentFailures] as $kind => $failures) {
            if ([] !== $failures) {
                $id = array_key_first($failures);
                $parts[] = \sprintf('%d %s failure(s) (first: %s: %s)', \count($failures), $kind, $id, $failures[$id]);
            }
        }

        return [] === $parts ? 'none' : implode(', ', $parts);
    }
}
