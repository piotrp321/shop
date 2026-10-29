<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Application;

use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Query\QueueStatus;

final class FakeMessagingStatistics implements MessagingStatistics
{
    public int $calls = 0;

    /**
     * @param list<QueueStatus>|null $queues
     */
    public function __construct(private readonly int $outboxPending, private readonly ?array $queues)
    {
    }

    public function outboxPending(): int
    {
        ++$this->calls;

        return $this->outboxPending;
    }

    public function startQueues(): \Closure
    {
        ++$this->calls;

        return fn (): ?array => $this->queues;
    }
}
