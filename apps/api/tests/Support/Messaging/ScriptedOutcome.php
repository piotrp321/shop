<?php

declare(strict_types=1);

namespace App\Tests\Support\Messaging;

/**
 * Message handled by {@see ScriptedOutcomeHandler}: the handler does, attempt after attempt, what the test scripted for
 * the key, so the tests of the failure transport can make a message fail and then succeed.
 */
final readonly class ScriptedOutcome
{
    public function __construct(public string $key)
    {
    }
}
