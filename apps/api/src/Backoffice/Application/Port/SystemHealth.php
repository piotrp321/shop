<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

use App\Backoffice\Application\Query\ServiceStatus;

interface SystemHealth
{
    /**
     * The state of every service, in no particular order. Never fails: an unreachable service is a down one.
     *
     * @return list<ServiceStatus>
     */
    public function services(): array;
}
