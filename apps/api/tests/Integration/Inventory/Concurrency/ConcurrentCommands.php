<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Concurrency;

use App\Kernel;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Dispatches commands on command.bus at the same moment, each in a process of its own: a pcntl_fork child that boots
 * its own kernel and opens its own database connection after the fork, so the participants are separate
 * transactions on separate connections, as concurrent requests are.
 *
 * The start is a barrier on a PostgreSQL advisory lock: the parent holds it exclusively, every child waits for it in
 * shared mode once its kernel and connection are ready, and the parent releases it when all of them wait, so all
 * children go at once.
 *
 * A child writes its outcome to a file of its own and kills itself (SIGKILL): it must never run the destructors and
 * shutdown functions it inherited, which would close the parent's database connections (the sockets are shared
 * after the fork) and PHPUnit's output. The parent waits for every child and refuses a run in which a child did not
 * reach the barrier, did not finish in time or left no outcome.
 */
final class ConcurrentCommands
{
    /** The first key of the barrier's advisory lock, chosen for these tests; the second is random per run. */
    private const int BARRIER_NAMESPACE = 0x1A7E;
    private const float TIMEOUT_SECONDS = 60.0;

    /**
     * @param list<object> $commands
     * @param string       $environment the kernel environment of the participants: `test_messaging` for the ones that
     *                                  must write to the real Doctrine transports instead of the in-memory ones
     *
     * @return list<Outcome> in the order of the commands
     */
    public static function run(Connection $control, array $commands, string $environment = 'test'): array
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            throw new \RuntimeException('The concurrency tests need the pcntl and posix extensions.');
        }

        // Garbage cycles the parent has not collected yet are inherited by every child, whose kernel shutdown
        // (gc_collect_cycles) then runs their destructors there: an AMQP connection left by an earlier test closes on
        // a socket it shares with the parent and waits for a reply that never comes (about 30 s). The parent collects
        // them first.
        gc_collect_cycles();

        $barrier = random_int(1, 0x7FFFFFFF);
        $directory = sys_get_temp_dir().'/inventory-concurrency-'.bin2hex(random_bytes(8));
        if (!mkdir($directory, 0o700)) {
            throw new \RuntimeException('Cannot create the directory of the outcomes.');
        }

        $P0 = hrtime(true);
        $control->executeStatement('SELECT pg_advisory_lock(CAST(? AS int), CAST(? AS int))', [self::BARRIER_NAMESPACE, $barrier]);
        $children = [];
        $problems = [];
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        try {
            foreach ($commands as $index => $command) {
                $pid = pcntl_fork();
                if (-1 === $pid) {
                    $problems[] = 'Cannot fork a participant.';
                    break;
                }
                if (0 === $pid) {
                    self::participate($command, self::outcomeFile($directory, $index), $barrier, $environment);
                }
                $children[$index] = $pid;
            }

            $P1 = hrtime(true);
            if ([] === $problems) {
                $problems = self::waitAtTheBarrier($control, $barrier, $children, $deadline);
            }
            $P2 = hrtime(true);
        } finally {
            $control->executeStatement('SELECT pg_advisory_unlock(CAST(? AS int), CAST(? AS int))', [self::BARRIER_NAMESPACE, $barrier]);
        }

        $problems = [...$problems, ...self::waitForAll($children, $deadline)];
        $P3 = hrtime(true);
        file_put_contents('php://stderr', sprintf("RUNPHASES n=%d %s fork %.0f barrier %.0f waitall %.0f ms\n", \count($commands), $commands[0]::class, ($P1 - $P0) / 1e6, ($P2 - $P1) / 1e6, ($P3 - $P2) / 1e6));
        $outcomes = [];
        foreach (array_keys($children) as $index) {
            $file = self::outcomeFile($directory, $index);
            $content = is_file($file) ? file_get_contents($file) : false;
            if (\is_string($content)) {
                unlink($file);
            }
            if (!\is_string($content) || '' === $content) {
                $problems[] = \sprintf('Participant %d left no outcome.', $index);
                continue;
            }
            $outcome = Outcome::fromJson($content);
            if ($outcome->isParticipantFailure()) {
                $problems[] = \sprintf('Participant %d failed outside its command: %s', $index, $outcome->message);
            }
            $outcomes[] = $outcome;
        }
        rmdir($directory);

        if ([] !== $problems) {
            throw new \RuntimeException(implode(' ', $problems));
        }

        return $outcomes;
    }

    /**
     * Never returns: the process kills itself. POSIX delivers a signal a process sends to itself before kill()
     * returns, and SIGKILL cannot be blocked.
     */
    private static function participate(object $command, string $outcomeFile, int $barrier, string $environment): void
    {
        // A fatal error (e.g. the time limit) is no Throwable: the process still ends before any destructor runs
        // (the shutdown functions inherited from the parent run first; they only report), and writes nothing into
        // PHPUnit's output. The run fails because the participant left no outcome.
        ini_set('display_errors', '0');
        register_shutdown_function(static function (): void {
            posix_kill(posix_getpid(), \SIGKILL);
        });

        $outcome = Outcome::participantFailure('The participant stopped before its command finished.');
        try {
            // The parent's DAMA connection belongs to the parent (and to its open transaction): this process opens
            // a connection of its own, which commits.
            $T0 = hrtime(true); $R0 = getrusage();
            StaticDriver::setKeepStaticConnections(false);
            $kernel = new Kernel($environment, false);
            $kernel->boot();
            $T1 = hrtime(true);
            $container = $kernel->getContainer()->get('test.service_container');
            \assert($container instanceof ContainerInterface);
            $connection = $container->get('doctrine.dbal.default_connection');
            $bus = $container->get('command.bus');
            \assert($connection instanceof Connection && $bus instanceof MessageBusInterface);

            // A participant that waits for a lock that is never released ends with an error instead of hanging.
            $T2 = hrtime(true);
            $connection->executeStatement("SET statement_timeout = '30s'");
            $connection->executeStatement('SELECT pg_advisory_lock_shared(CAST(? AS int), CAST(? AS int))', [self::BARRIER_NAMESPACE, $barrier]);

            $T3 = hrtime(true);
            try {
                $result = $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
                $outcome = Outcome::success($result);
            } catch (\Throwable $failure) {
                // The bus wraps what the handler threw.
                $wrapped = $failure instanceof HandlerFailedException ? array_values($failure->getWrappedExceptions()) : [];
                $outcome = Outcome::failure(1 === \count($wrapped) ? $wrapped[0] : $failure);
            }

            $T4 = hrtime(true); $R1 = getrusage();
            file_put_contents('php://stderr', sprintf("PHASES ".$command::class." t=%.3f boot %.0f services %.0f barrier %.0f dispatch %.0f ms cpu %.0f ms\n", microtime(true), ($T1-$T0)/1e6, ($T2-$T1)/1e6, ($T3-$T2)/1e6, ($T4-$T3)/1e6, (($R1['ru_utime.tv_sec']-$R0['ru_utime.tv_sec'])+($R1['ru_utime.tv_usec']-$R0['ru_utime.tv_usec'])/1e6+($R1['ru_stime.tv_sec']-$R0['ru_stime.tv_sec'])+($R1['ru_stime.tv_usec']-$R0['ru_stime.tv_usec'])/1e6)*1000));
            $connection->executeStatement('SELECT pg_advisory_unlock_shared(CAST(? AS int), CAST(? AS int))', [self::BARRIER_NAMESPACE, $barrier]);
            $connection->close();
        } catch (\Throwable $failure) {
            $outcome = Outcome::participantFailure($failure::class.': '.$failure->getMessage());
        }

        file_put_contents($outcomeFile, $outcome->toJson());
        posix_kill(posix_getpid(), \SIGKILL);
    }

    /**
     * @param array<int, int> $children index => pid
     *
     * @return list<string> the problems
     */
    private static function waitAtTheBarrier(Connection $control, int $barrier, array $children, float $deadline): array
    {
        while (true) {
            $waiting = $control->fetchOne(
                "SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = CAST(? AS int)::oid AND objid = CAST(? AS int)::oid AND objsubid = 2 AND NOT granted",
                [self::BARRIER_NAMESPACE, $barrier],
            );
            if ($waiting === \count($children)) {
                return [];
            }
            foreach ($children as $index => $pid) {
                // A child that ended before the barrier failed while booting; its outcome tells why.
                if (0 !== pcntl_waitpid($pid, $status, \WNOHANG | \WUNTRACED)) {
                    return [];
                }
            }
            if (microtime(true) > $deadline) {
                return [\sprintf('Only %s of %d participants reached the barrier in time.', var_export($waiting, true), \count($children))];
            }
            usleep(5000);
        }
    }

    /**
     * @param array<int, int> $children index => pid
     *
     * @return list<string> the problems
     */
    private static function waitForAll(array $children, float $deadline): array
    {
        $problems = [];
        $remaining = $children;
        while ([] !== $remaining) {
            foreach ($remaining as $index => $pid) {
                $result = pcntl_waitpid($pid, $status, \WNOHANG);
                // -1: already reaped while waiting at the barrier.
                if (0 !== $result) {
                    unset($remaining[$index]);
                }
            }
            if ([] === $remaining) {
                break;
            }
            if (microtime(true) > $deadline) {
                foreach ($remaining as $index => $pid) {
                    posix_kill($pid, \SIGKILL);
                    pcntl_waitpid($pid, $status);
                    $problems[] = \sprintf('Participant %d did not finish in time.', $index);
                }
                break;
            }
            usleep(5000);
        }

        return $problems;
    }

    private static function outcomeFile(string $directory, int $index): string
    {
        return $directory.'/'.$index.'.json';
    }
}
