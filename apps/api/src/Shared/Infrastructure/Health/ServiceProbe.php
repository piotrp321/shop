<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Checks that one service of the stack answers. A probe never throws: an unreachable or failing service is down,
 * and it waits for the service at most {@see self::LIMIT_SECONDS}.
 */
#[AutoconfigureTag('app.health_probe')]
interface ServiceProbe
{
    /** How long a probe waits for its service. */
    public const float LIMIT_SECONDS = 1.0;

    /**
     * The name of the service in the system status, e.g. `postgres`.
     */
    public function name(): string;

    public function probe(): ProbeResult;
}
