<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * The outcome of a merge. `truncated`: a sum was cut to Cart::MAX_QUANTITY or a guest line was skipped because the
 * customer cart had Cart::MAX_LINES lines; only a merge that moved the lines can be truncated.
 */
final readonly class MergeOutcome
{
    private function __construct(
        public MergeOutcomeResult $result,
        public bool $truncated,
    ) {
    }

    public static function merged(bool $truncated): self
    {
        return new self(MergeOutcomeResult::Merged, $truncated);
    }

    public static function alreadyMerged(): self
    {
        return new self(MergeOutcomeResult::AlreadyMerged, false);
    }

    public static function nothingToMerge(): self
    {
        return new self(MergeOutcomeResult::NothingToMerge, false);
    }
}
