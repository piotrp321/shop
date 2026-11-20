<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\FailedMessages;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'Messages that failed all their attempts and wait in the `failed` transport for a decision of the operator.', required: ['count', 'capped', 'oldestAt'])]
final readonly class DashboardFailedMessagesResponse
{
    public function __construct(
        #[OA\Property(description: 'Messages in `failed`, counted up to 1000.', example: 0)]
        public int $count,
        #[OA\Property(description: 'More than 1000 messages: `count` is 1000.', example: false)]
        public bool $capped,
        #[OA\Property(description: 'When the oldest of them was written to `failed`, RFC 3339 in UTC. Null without any.', format: 'date-time', example: '2026-11-20T10:15:00+00:00', nullable: true)]
        public ?string $oldestAt,
    ) {
    }

    public static function of(FailedMessages $failed): self
    {
        return new self($failed->count->value, $failed->count->capped, $failed->oldestAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }
}
