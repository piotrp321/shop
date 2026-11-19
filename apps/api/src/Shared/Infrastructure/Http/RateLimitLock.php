<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * The lock of the key of a rate limiter, made by RateLimitLockFactory: a bounded wait instead of the endless one of
 * acquire(true), and fail open with a warning on a failure of Redis (see RateLimitLockFactory).
 */
final readonly class RateLimitLock implements SharedLockInterface
{
    /** Milliseconds between two attempts at a held key (with some jitter, so waiting requests do not move together). */
    private const int RETRY_MIN_MS = 10;
    private const int RETRY_MAX_MS = 30;

    public function __construct(
        private SharedLockInterface $lock,
        private string $resource,
        private float $wait,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Takes the lock, waiting at most the wait of the factory whether $blocking or not (the limiter always blocks).
     *
     * @throws RateLimitLockContended   another request held the key for the whole wait
     * @throws RateLimitLockUnavailable Redis failed (logged)
     */
    public function acquire(bool $blocking = false): bool
    {
        $deadline = hrtime(true) + (int) ($this->wait * 1e9);
        while (true) {
            try {
                if ($this->lock->acquire(false)) {
                    return true;
                }
            } catch (LockAcquiringException $failure) {
                $this->logger->warning('Failed to take the lock of the rate limit "{resource}"; the request passes without a limit.', ['resource' => $this->resource, 'exception' => $failure]);

                throw new RateLimitLockUnavailable($failure);
            }

            $left = $deadline - hrtime(true);
            if ($left <= 0) {
                throw new RateLimitLockContended();
            }
            usleep(min(random_int(self::RETRY_MIN_MS, self::RETRY_MAX_MS) * 1000, intdiv($left, 1000) + 1));
        }
    }

    public function acquireRead(bool $blocking = false): bool
    {
        return $this->lock->acquireRead($blocking);
    }

    public function refresh(?float $ttl = null): void
    {
        $this->lock->refresh($ttl);
    }

    public function isAcquired(): bool
    {
        return $this->lock->isAcquired();
    }

    /**
     * Never throws: a lock that cannot be released expires by itself.
     */
    public function release(): void
    {
        try {
            $this->lock->release();
        } catch (LockReleasingException $failure) {
            $this->logger->warning('Failed to release the lock of the rate limit "{resource}"; it expires by itself.', ['resource' => $this->resource, 'exception' => $failure]);
        }
    }

    public function isExpired(): bool
    {
        return $this->lock->isExpired();
    }

    public function getRemainingLifetime(): ?float
    {
        return $this->lock->getRemainingLifetime();
    }
}
