<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

/**
 * A probe that can send its request first and collect the answer later, so that the probes of many services
 * wait for their services at the same time instead of one after another.
 */
interface ConcurrentServiceProbe extends ServiceProbe
{
    /**
     * Sends the request without waiting for the answer.
     *
     * @return \Closure(): ProbeResult waits for the answer (within the limit counted from the start)
     */
    public function start(): \Closure;
}
