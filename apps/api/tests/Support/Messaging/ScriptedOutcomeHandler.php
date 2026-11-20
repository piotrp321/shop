<?php

declare(strict_types=1);

namespace App\Tests\Support\Messaging;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Handles {@see ScriptedOutcome} as the test scripted it for its key and counts the attempts. The script and the
 * counts are static, because the worker of messenger:failed:retry runs the handler of the container of the kernel;
 * {@see self::reset()} clears them before each test.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ScriptedOutcomeHandler
{
    public const string OK = 'ok';
    /** A plain error: Messenger retries it only as far as the retry strategy of the transport allows. */
    public const string ERROR = 'error';
    public const string UNRECOVERABLE = 'unrecoverable';
    /** Messenger retries it whatever the retry strategy says, after RECOVERABLE_DELAY_MS (like NotificationSender). */
    public const string RECOVERABLE = 'recoverable';

    public const int RECOVERABLE_DELAY_MS = 60_000;

    /** @var array<string, list<string>> */
    private static array $scripts = [];

    /** @var array<string, int> */
    private static array $attempts = [];

    public static function reset(): void
    {
        self::$scripts = [];
        self::$attempts = [];
    }

    /**
     * The outcomes of the next attempts for the key, in order; the last one repeats. A key without a script succeeds.
     */
    public static function script(string $key, string ...$outcomes): void
    {
        self::$scripts[$key] = array_values($outcomes);
    }

    public static function attempts(string $key): int
    {
        return self::$attempts[$key] ?? 0;
    }

    public function __invoke(ScriptedOutcome $message): void
    {
        $attempt = self::$attempts[$message->key] = self::attempts($message->key) + 1;
        $script = self::$scripts[$message->key] ?? [self::OK];
        $outcome = $script[min($attempt, \count($script)) - 1];
        $reason = \sprintf('Scripted %s of "%s", attempt %d.', $outcome, $message->key, $attempt);

        match ($outcome) {
            self::OK => null,
            self::ERROR => throw new \RuntimeException($reason),
            self::UNRECOVERABLE => throw new UnrecoverableMessageHandlingException($reason),
            self::RECOVERABLE => throw new RecoverableMessageHandlingException($reason, retryDelay: self::RECOVERABLE_DELAY_MS),
            default => throw new \LogicException('Unknown outcome '.$outcome),
        };
    }
}
