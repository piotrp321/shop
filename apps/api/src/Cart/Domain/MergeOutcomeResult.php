<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * How a merge of a guest cart into a customer cart ended: the guest lines moved (merged), the guest cart was merged
 * before (already_merged), or there is no guest cart to merge: unknown, expired or a customer cart (nothing_to_merge).
 */
enum MergeOutcomeResult: string
{
    case Merged = 'merged';
    case AlreadyMerged = 'already_merged';
    case NothingToMerge = 'nothing_to_merge';
}
