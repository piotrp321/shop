<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * The key of a rate limit stayed locked by another request for the whole wait (RateLimitLock): the request is refused
 * like one over the limit (RateLimitSubscriber).
 */
final class RateLimitLockContended extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The lock of the rate limit is held by another request.');
    }
}
