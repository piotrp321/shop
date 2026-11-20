<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

use App\Shared\Domain\BoundedCount;

/**
 * The messages that failed all their attempts and wait in the `failed` transport for a decision of the operator.
 */
final readonly class FailedMessages
{
    /**
     * @param ?\DateTimeImmutable $oldestAt when the oldest of them was written to `failed` (UTC), null without any
     */
    public function __construct(
        public BoundedCount $count,
        public ?\DateTimeImmutable $oldestAt,
    ) {
    }
}
