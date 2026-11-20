<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

/**
 * The queues of the database as read, before they are judged.
 */
final readonly class DatabaseQueues
{
    /**
     * @param list<TransportBacklog> $transports in a fixed order
     */
    public function __construct(
        public FailedMessages $failed,
        public array $transports,
    ) {
    }
}
