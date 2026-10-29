<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

final readonly class ServiceStatus
{
    /**
     * @param ?int $latencyMs null for a service that is down
     */
    public function __construct(
        public string $name,
        public bool $up,
        public ?int $latencyMs,
    ) {
    }
}
