<?php

declare(strict_types=1);

namespace App\Shared\UI\Console;

/**
 * What the worker events of one `messenger:failed:retry <id>` of {@see FailedRetryCommand} said about the message.
 */
final class FailedRetryOutcome
{
    /** The handling succeeded and the message was removed. */
    public bool $handled = false;

    /** The handling failed; the message stays in `failed` under $newId. */
    public bool $failed = false;

    /** Kept by the delayed retry of Messenger (RecoverableExceptionInterface), not by a copy available at once. */
    public bool $delayed = false;

    public ?string $newId = null;
}
