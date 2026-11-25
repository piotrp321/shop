<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

use App\Shared\Domain\BoundedCount;

final readonly class TransportStatus
{
    /**
     * @param bool $stalled the oldest ready message waits longer than the limit: nothing takes the messages of the queue
     */
    public function __construct(
        public string $name,
        public BoundedCount $ready,
        public ?int $oldestReadyAgeSeconds,
        public bool $stalled,
    ) {
    }
}
