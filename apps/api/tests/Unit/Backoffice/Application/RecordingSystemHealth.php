<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Application;

use App\Backoffice\Application\Port\SystemHealth;
use App\Backoffice\Application\Query\ServiceStatus;

final class RecordingSystemHealth implements SystemHealth
{
    public int $calls = 0;

    /**
     * @param list<ServiceStatus> $services
     */
    public function __construct(private readonly array $services)
    {
    }

    public function services(): array
    {
        ++$this->calls;

        return $this->services;
    }
}
