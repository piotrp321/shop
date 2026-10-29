<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure;

use App\Backoffice\Application\Port\SystemHealth;
use App\Backoffice\Application\Query\ServiceStatus;
use App\Shared\Infrastructure\Health\ConcurrentServiceProbe;
use App\Shared\Infrastructure\Health\ProbeResult;
use App\Shared\Infrastructure\Health\ServiceProbe;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every probe of the stack. The requests of the HTTP probes are sent first and collected last, so the slow
 * ones wait together, and the others (database, Redis) run while those requests are in flight.
 */
final readonly class ProbingSystemHealth implements SystemHealth
{
    /**
     * @param iterable<ServiceProbe> $probes
     */
    public function __construct(#[AutowireIterator('app.health_probe')] private iterable $probes)
    {
    }

    public function services(): array
    {
        /** @var list<array{ServiceProbe, ProbeResult|\Closure(): ProbeResult}> $runs */
        $runs = [];
        foreach ($this->probes as $probe) {
            $runs[] = [$probe, $probe instanceof ConcurrentServiceProbe ? $probe->start() : $probe->probe()];
        }

        return array_map(static function (array $run): ServiceStatus {
            [$probe, $result] = $run;
            $result = $result instanceof ProbeResult ? $result : $result();

            return new ServiceStatus($probe->name(), $result->up, $result->latencyMs);
        }, $runs);
    }
}
