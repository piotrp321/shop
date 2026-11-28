<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\TransportStatus;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A queue of the database that a worker takes messages from.', required: ['name', 'ready', 'capped', 'oldestReadyAgeSeconds', 'stalled'])]
final readonly class DashboardTransportResponse
{
    public function __construct(
        #[OA\Property(description: 'The name of the transport: outbox, scheduled, payment_simulator, promotions, jobs or indexing_finalize.', example: 'outbox')]
        public string $name,
        #[OA\Property(description: 'Messages that a worker could take now (not handed to a worker, due), counted up to 1000.', example: 0)]
        public int $ready,
        #[OA\Property(description: 'More than 1000 ready messages: `ready` is 1000.', example: false)]
        public bool $capped,
        #[OA\Property(description: 'How long the oldest ready message has been ready, in seconds (a delayed message from the moment it became due). Null without a ready message.', example: 3, nullable: true)]
        public ?int $oldestReadyAgeSeconds,
        #[OA\Property(description: 'Whether the oldest ready message waits longer than the limit of the API (600 s): nothing takes the messages of the queue.', example: false)]
        public bool $stalled,
    ) {
    }

    public static function of(TransportStatus $transport): self
    {
        return new self($transport->name, $transport->ready->value, $transport->ready->capped, $transport->oldestReadyAgeSeconds, $transport->stalled);
    }
}
