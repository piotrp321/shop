<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * Redis failed while the lock of a rate limit was taken (RateLimitLock, which logged it): the limiter does not call its
 * counter and the request passes without a limit (RateLimitSubscriber).
 */
final class RateLimitLockUnavailable extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('The lock of the rate limit cannot be taken.', 0, $previous);
    }
}
