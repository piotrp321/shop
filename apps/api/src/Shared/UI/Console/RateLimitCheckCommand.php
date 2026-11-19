<?php

declare(strict_types=1);

namespace App\Shared\UI\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Checks that the Redis of the rate limiters is usable for the API (spec: docs/features/abuse-limits/spec.md, L12, D16),
 * the way the limiters use it: over the connection of `default_redis_provider` (the DSN of the counters and the locks of
 * config/packages/rate_limiter.yaml, with its timeouts), without the fail-open layer of the limiters. It writes a control
 * key with a time to live, reads it and deletes it. The CronJob rate-limit-check of the chart runs it every five minutes,
 * and the alert RateLimitsOff fires when no run has succeeded for 17 minutes.
 *
 * The key is outside the keys of the counters and the locks, so the command uses no limit. A failure is an exit code and
 * a reason in the output, not an exception: the console would report it to Sentry on every run of a failing Redis.
 *
 * Exit codes: 0 the key was written, read and deleted; 2 any failure (no connection, a timeout, an error reply such as
 * MISCONF of a Redis that cannot save to its disk, another value than the one written, a key that was not there).
 */
#[AsCommand(name: 'rate-limit:check', description: 'Writes, reads and deletes a control key in the Redis of the rate limiters; exit code 2 when that fails.')]
final readonly class RateLimitCheckCommand
{
    public const int ERROR = 2;

    /** Seconds after which Redis drops the key if the command never deletes it (a crash between the write and the delete). */
    private const int KEY_TTL = 60;

    /**
     * @param \Closure(): \Redis $connection opens the connection (the lazy `default_redis_provider`), so that a failure of it is caught here
     */
    public function __construct(
        #[AutowireServiceClosure('cache.default_redis_provider')] private \Closure $connection,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $this->check();
        } catch (\Throwable $failure) {
            // One line each, not the wrapping blocks of SymfonyStyle: in a pod (no terminal) a block wraps at about 74 characters, and
            // the smoke test and the runbook look for these texts in the log of the Job.
            $io->writeln('<error>The Redis of the rate limiters failed: '.OutputFormatter::escape($failure->getMessage()).'</error>');

            return self::ERROR;
        }

        $io->writeln('<info>The Redis of the rate limiters accepted, returned and deleted the control key.</info>');

        return Command::SUCCESS;
    }

    /**
     * @throws \RuntimeException the Redis did not do what the check asked for
     */
    private function check(): void
    {
        $key = 'rate-limit-check:'.bin2hex(random_bytes(16));
        $value = bin2hex(random_bytes(16));

        $redis = ($this->connection)();
        try {
            $written = $redis->set($key, $value, ['EX' => self::KEY_TTL]);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('the write of the control key failed: '.$failure->getMessage(), previous: $failure);
        }
        if (true !== $written) {
            throw new \RuntimeException('the write of the control key was not accepted.');
        }

        try {
            $read = $redis->get($key);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('the read of the control key failed: '.$failure->getMessage(), previous: $failure);
        }
        if (false === $read) {
            throw new \RuntimeException('the control key was not there on the read.');
        }
        if ($read !== $value) {
            throw new \RuntimeException('the read returned another value than the one written.');
        }

        try {
            $deleted = $redis->del($key);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('the delete of the control key failed: '.$failure->getMessage(), previous: $failure);
        }
        if (1 !== $deleted) {
            throw new \RuntimeException('the delete of the control key removed nothing.');
        }
    }
}
