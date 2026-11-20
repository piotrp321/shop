<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

use App\Backoffice\Application\Port\MessagingStatistics;

/**
 * The only place that judges the queues of the database (spec: docs/features/async-reliability/spec.md, W7). A queue
 * stands when its oldest ready message waits longer than `app.messaging.stalled_after_seconds`; the messaging is healthy
 * when nothing is in `failed` and no queue stands.
 */
final readonly class MessagingHealth
{
    public function __construct(
        private MessagingStatistics $statistics,
        private int $stalledAfterSeconds,
    ) {
    }

    public function check(): MessagingSection
    {
        $queues = $this->statistics->databaseQueues();

        $transports = array_map(
            fn (TransportBacklog $transport): TransportStatus => new TransportStatus(
                $transport->name,
                $transport->ready,
                $transport->oldestReadyAgeSeconds,
                null !== $transport->oldestReadyAgeSeconds && $transport->oldestReadyAgeSeconds > $this->stalledAfterSeconds,
            ),
            $queues->transports,
        );

        $stalled = array_any($transports, static fn (TransportStatus $transport): bool => $transport->stalled);

        return new MessagingSection($queues->failed, $transports, 0 === $queues->failed->count->value && !$stalled);
    }
}
