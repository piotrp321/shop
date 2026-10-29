<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

use App\Backoffice\Application\Query\QueueStatus;

interface MessagingStatistics
{
    /**
     * Events waiting in the outbox to be relayed to the broker.
     */
    public function outboxPending(): int;

    /**
     * Queues of the broker, null when its management API does not answer.
     *
     * @return list<QueueStatus>|null
     */
    public function queues(): ?array;
}
