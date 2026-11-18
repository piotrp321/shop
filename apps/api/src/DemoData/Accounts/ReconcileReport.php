<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

/**
 * What a run of the reconciliation did in one realm. `notMembers` are the accounts chosen for deletion that were no
 * longer members when their groups were read (skipped), `notFound` the ones that no longer existed; `conflicts` lists
 * the accounts of the target that could not be written without changing a foreign account.
 */
final readonly class ReconcileReport
{
    /**
     * @param list<string> $notMembers
     * @param list<string> $conflicts
     */
    public function __construct(
        public bool $groupCreated,
        public int $kept,
        public int $deleted,
        public int $notFound,
        public array $notMembers,
        public int $imported,
        public array $conflicts,
    ) {
    }
}
