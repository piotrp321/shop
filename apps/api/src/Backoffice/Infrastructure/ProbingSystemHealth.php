<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure;

use App\Backoffice\Application\Port\SystemHealth;
use App\Backoffice\Application\Query\ServiceStatus;
use App\Shared\Infrastructure\Health\ConcurrentServiceProbe;
use App\Shared\Infrastructure\Health\Probe\HttpProbe;
use App\Shared\Infrastructure\Health\ProbeResult;
use App\Shared\Infrastructure\Health\ServiceProbe;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every probe of the stack. Probes that can send their request first (HTTP, Redis) are all started before
 * any answer is awaited, so their waits overlap: collecting takes about as long as the slowest one, at most 1 s.
 * The others (the database, which answers in milliseconds or fails) run when the results are collected.
 */
final readonly class ProbingSystemHealth implements SystemHealth
{
    /**
     * @param iterable<ServiceProbe> $probes
     */
    public function __construct(#[AutowireIterator('app.health_probe')] private iterable $probes)
    {
    }

    public function start(): \Closure
    {
        /** @var list<array{ServiceProbe, ?\Closure(): ProbeResult}> $runs */
        $runs = [];
        foreach ($this->probes as $probe) {
            $runs[] = [$probe, $probe instanceof ConcurrentServiceProbe ? $probe->start() : null];
        }

        return static function () use ($runs): array {
            // Awaiting an HTTP probe drives the client, and with it every other request in flight, so those go first:
            // another probe that waits on its own socket for a whole second would stall them. The database answers
            // in milliseconds, or fails.
            $order = static fn (array $run): int => null === $run[1] ? 0 : ($run[0] instanceof HttpProbe ? 1 : 2);
            usort($runs, static fn (array $a, array $b): int => $order($a) <=> $order($b));

            $statuses = [];
            foreach ($runs as [$probe, $await]) {
                $result = null === $await ? $probe->probe() : $await();
                $statuses[] = new ServiceStatus($probe->name(), $result->up, $result->latencyMs);
            }

            return $statuses;
        };
    }
}
