<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Message handled by {@see TestMessageHandler} in the test environment.
 */
final readonly class TestMessage
{
    public function __construct(public string $failure)
    {
    }
}
