<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Application;

use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Query\DatabaseQueues;
use App\Backoffice\Application\Query\FailedMessages;
use App\Backoffice\Application\Query\QueueStatus;
use App\Shared\Domain\BoundedCount;

final class FakeMessagingStatistics implements MessagingStatistics
{
    public int $calls = 0;

    /**
     * @param list<QueueStatus>|null $queues
     */
    public function __construct(private readonly int $outboxPending, private readonly ?array $queues, private ?DatabaseQueues $database = null)
    {
        $this->database ??= new DatabaseQueues(new FailedMessages(new BoundedCount(0, false), null), []);
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

    public function databaseQueues(): DatabaseQueues
    {
        ++$this->calls;
        \assert(null !== $this->database);

        return $this->database;
    }
}
