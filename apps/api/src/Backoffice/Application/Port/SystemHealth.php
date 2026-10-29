<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

use App\Backoffice\Application\Query\ServiceStatus;

interface SystemHealth
{
    /**
     * Sends the checks of all services and returns what collects their results, so that the caller can start other
     * slow requests before waiting. The services are checked together: collecting takes about as long as the
     * slowest one (at most 1 s), not the sum. Never fails: an unreachable service is a down one.
     *
     * @return \Closure(): list<ServiceStatus> the states of all services, in no particular order
     */
    public function start(): \Closure;
}
