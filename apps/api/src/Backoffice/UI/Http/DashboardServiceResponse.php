<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\ServiceStatus;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The state of one service of the stack.', required: ['name', 'status', 'latencyMs'])]
final readonly class DashboardServiceResponse
{
    public function __construct(
        #[OA\Property(example: 'postgres')]
        public string $name,
        #[OA\Property(type: 'string', enum: ['up', 'down'], example: 'up')]
        public string $status,
        #[OA\Property(description: 'How long the service took to answer, in milliseconds. Null when it is down.', example: 3, nullable: true)]
        public ?int $latencyMs,
    ) {
    }

    public static function of(ServiceStatus $service): self
    {
        return new self($service->name, $service->up ? 'up' : 'down', $service->latencyMs);
    }
}
