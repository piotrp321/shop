<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\QueueStatus;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A queue of the message broker.')]
final readonly class DashboardQueueResponse
{
    public function __construct(
        #[OA\Property(example: 'events')]
        public string $name,
        #[OA\Property(description: 'Messages in the queue.', example: 0)]
        public int $messages,
        #[OA\Property(description: 'Consumers subscribed to the queue.', example: 1)]
        public int $consumers,
    ) {
    }

    public static function of(QueueStatus $queue): self
    {
        return new self($queue->name, $queue->messages, $queue->consumers);
    }
}
