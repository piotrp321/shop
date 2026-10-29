<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The outbox of domain events.')]
final readonly class DashboardOutboxResponse
{
    public function __construct(
        #[OA\Property(description: 'Events waiting to be relayed to the broker.', example: 0)]
        public int $pending,
    ) {
    }
}
