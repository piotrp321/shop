<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Runs participants at the same moment, each in a process of its own (pcntl_fork), like ConcurrentCommands of the
 * Inventory tests but for any code, not only commands: a participant first prepares itself in its process (opens its
 * own connection, boots its own kernel) and returns the action, which runs once every participant is ready.
 *
 * The start is the same barrier on a PostgreSQL advisory lock: the parent holds it exclusively, every child waits for
 * it in shared mode on a connection of its own, and the parent releases it when all of them wait. A child writes what
 * its action returned (or the class and message of what it threw) to a file and kills itself (SIGKILL), so it never
 * runs the destructors it inherited, which would close the parent's connections.
 */
final class ParallelProcesses
{
    private const int BARRIER_NAMESPACE = 0x1A7F;
    private const float TIMEOUT_SECONDS = 60.0;
    private const string PARTICIPANT_FAILURE = 'participant failure: ';

    /**
     * @param list<\Closure(): (\Closure(): string)> $participants
     *
     * @return list<string> what each action returned, or "<class of the throwable>: <message>", in the order of the
     *                      participants
     */
    public static function run(Connection $control, array $participants): array
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
        $directory = sys_get_temp_dir().'/ordering-parallel-'.bin2hex(random_bytes(8));
        if (!mkdir($directory, 0o700)) {
            throw new \RuntimeException('Cannot create the directory of the outcomes.');
        }

        $control->executeStatement('SELECT pg_advisory_lock(CAST(? AS int), CAST(? AS int))', [self::BARRIER_NAMESPACE, $barrier]);
        $children = [];
        $problems = [];
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        try {
            foreach ($participants as $index => $prepare) {
                $pid = pcntl_fork();
                if (-1 === $pid) {
                    $problems[] = 'Cannot fork a participant.';
                    break;
                }
                if (0 === $pid) {
                    self::participate($control, $prepare, $directory.'/'.$index, $barrier);
                }
                $children[$index] = $pid;
            }
            if ([] === $problems) {
                $problems = self::waitAtTheBarrier($control, $barrier, $children, $deadline);
            }
        } finally {
            $control->executeStatement('SELECT pg_advisory_unlock(CAST(? AS int), CAST(? AS int))', [self::BARRIER_NAMESPACE, $barrier]);
        }

        $problems = [...$problems, ...self::waitForAll($children, $deadline)];
        $outcomes = [];
        foreach (array_keys($children) as $index) {
            $file = $directory.'/'.$index;
            $content = is_file($file) ? file_get_contents($file) : false;
            if (\is_string($content)) {
                unlink($file);
            }
            if (!\is_string($content) || '' === $content) {
                $problems[] = \sprintf('Participant %d left no outcome.', $index);
                continue;
            }
            if (str_starts_with($content, self::PARTICIPANT_FAILURE)) {
                $problems[] = \sprintf('Participant %d failed outside its action: %s', $index, $content);
            }
            $outcomes[] = $content;
        }
        rmdir($directory);

        if ([] !== $problems) {
            throw new \RuntimeException(implode(' ', $problems));
        }

        return $outcomes;
    }

    /**
     * Never returns: the process kills itself.
     *
     * @param \Closure(): (\Closure(): string) $prepare
     */
    private static function participate(Connection $control, \Closure $prepare, string $outcomeFile, int $barrier): void
    {
        ini_set('display_errors', '0');
        register_shutdown_function(static function (): void {
            posix_kill(posix_getpid(), \SIGKILL);
        });

        $outcome = self::PARTICIPANT_FAILURE.'the participant stopped before its action finished';
        try {
            // A kernel booted by the participant opens a connection of its own, which commits.
            StaticDriver::setKeepStaticConnections(false);
            $action = $prepare();
            /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
            $params = $control->getParams();
            $connection = DriverManager::getConnection($params, new DbalConfiguration());
            $connection->executeStatement("SET statement_timeout = '30s'");
            $connection->executeStatement('SELECT pg_advisory_lock_shared(CAST(? AS int), CAST(? AS int))', [self::BARRIER_NAMESPACE, $barrier]);
            try {
                $outcome = $action();
            } catch (\Throwable $failure) {
                $outcome = $failure::class.': '.$failure->getMessage();
            }
            $connection->executeStatement('SELECT pg_advisory_unlock_shared(CAST(? AS int), CAST(? AS int))', [self::BARRIER_NAMESPACE, $barrier]);
            $connection->close();
        } catch (\Throwable $failure) {
            $outcome = self::PARTICIPANT_FAILURE.$failure::class.': '.$failure->getMessage();
        }

        file_put_contents($outcomeFile, $outcome);
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
            foreach ($children as $pid) {
                // A child that ended before the barrier failed while preparing; its outcome tells why.
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
                // -1: already reaped while waiting at the barrier.
                if (0 !== pcntl_waitpid($pid, $status, \WNOHANG)) {
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
}
