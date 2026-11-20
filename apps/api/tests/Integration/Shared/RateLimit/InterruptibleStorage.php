<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * The storage of a limiter of RedisPoolKernel: the limiter reads its counter only while it holds the lock of the key,
 * so a test sets $beforeFetch to break Redis between the lock and the counter (and the release of the lock after it).
 */
final class InterruptibleStorage implements StorageInterface
{
    /** Called once before the next read of a counter, then cleared. */
    public static ?\Closure $beforeFetch = null;

    public function __construct(private readonly StorageInterface $inner)
    {
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->inner->save($limiterState);
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $callback = self::$beforeFetch;
        self::$beforeFetch = null;
        if (null !== $callback) {
            $callback();
        }

        return $this->inner->fetch($limiterStateId);
    }

    public function delete(string $limiterStateId): void
    {
        $this->inner->delete($limiterStateId);
    }
}
