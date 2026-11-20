<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Lock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\RedisStore;

/**
 * The lock factory of the rate limiters (`lock_factory` of every limiter in config/packages/rate_limiter.yaml; spec:
 * docs/features/abuse-limits/spec.md, D1). RateLimiterFactory creates the lock of a key with the TTL of 300 s of
 * LockFactory, takes it with acquire(true), which waits without a bound, and ignores its result. This factory gives
 * every lock instead:
 *
 * - a life of TTL seconds in Redis, set by the save itself (the initial TTL of the store, no refresh call), so a lock
 *   whose request died blocks its key at most that long;
 * - a wait of at most WAIT seconds for a key held by another request; then RateLimitLockContended, which
 *   RateLimitSubscriber answers with a 429, as contention must never open the limit;
 * - fail open on a failure of Redis: RateLimitLockUnavailable, so the limiter does not call its counter and
 *   RateLimitSubscriber lets the request through; a failed release does not stop the request. Both are a `warning` of
 *   the `cache` channel, like the failures of the pool of the counters.
 */
final class RateLimitLockFactory extends LockFactory
{
    /** Seconds a lock lives in Redis. */
    public const float TTL = 5.0;

    /** Seconds a request waits for a key held by another request. */
    public const float WAIT = 0.3;

    private readonly RedisStore $store;

    public function __construct(\Redis $redis, private readonly LoggerInterface $warnings)
    {
        $this->store = new RedisStore($redis, self::TTL);
        parent::__construct($this->store);
    }

    public function createLockFromKey(Key $key, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        // No TTL of the lock (the store gives it) and no release by the destructor (the limiter releases it).
        return new RateLimitLock(new Lock($key, $this->store, null, false), (string) $key, self::WAIT, $this->warnings);
    }
}
