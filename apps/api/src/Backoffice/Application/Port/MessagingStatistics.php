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
     * Sends the request for the queues of the broker and returns what waits for the answer (at most 1 s from the
     * start), so that it can wait together with other requests.
     *
     * @return \Closure(): ?list<QueueStatus> the queues, null when the management API does not answer
     */
    public function startQueues(): \Closure;
}
