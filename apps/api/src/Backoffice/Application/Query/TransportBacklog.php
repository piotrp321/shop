<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

use App\Shared\Domain\BoundedCount;

/**
 * What a worker could take from a queue of the database now: the messages that are ready (not handed to a worker, due).
 */
final readonly class TransportBacklog
{
    /**
     * @param ?int $oldestReadyAgeSeconds how long the oldest ready message has been ready (a delayed message from the
     *                                    moment it became due, not from its creation), null without a ready message
     */
    public function __construct(
        public string $name,
        public BoundedCount $ready,
        public ?int $oldestReadyAgeSeconds,
    ) {
    }
}
