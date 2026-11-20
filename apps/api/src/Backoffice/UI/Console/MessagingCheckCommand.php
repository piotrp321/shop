<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Console;

use App\Backoffice\Application\Query\MessagingHealth;
use App\Backoffice\Application\Query\TransportStatus;
use App\Shared\Domain\BoundedCount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Judges the queues of the database the way the dashboard does (spec: docs/features/async-reliability/spec.md, W7, W9):
 * `failed` is not empty, or a queue stands (its oldest ready message waits longer than app.messaging.stalled_after_seconds).
 * It prints one line for `failed` and one for every queue, never the content of a message, and reads only the database,
 * so it works while RabbitMQ is down. The CronJob queue-check of the chart runs it every five minutes, and the alert
 * MessagingUnhealthy fires when a Job fails.
 *
 * A failed read is an exit code and a reason in the output, not an exception: the console would report it to Sentry on
 * every run of a failing database (like rate-limit:check).
 *
 * Exit codes: 0 healthy; 1 `failed` is not empty or a queue stands; 2 the messages could not be read.
 */
#[AsCommand(name: 'app:messaging:check', description: 'Checks that no message is in `failed` and no queue of the database stands; exit code 1 when one does, 2 when they cannot be read.')]
final readonly class MessagingCheckCommand
{
    public const int UNHEALTHY = 1;
    public const int ERROR = 2;

    public function __construct(private MessagingHealth $health)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $messages = $this->health->check();
        } catch (\Throwable $failure) {
            // One line, not a block of SymfonyStyle (it wraps at about 74 characters in a pod): the runbook looks for this text in the log of the Job.
            $io->writeln('<error>The messages could not be read: '.OutputFormatter::escape($failure->getMessage()).'</error>');

            return self::ERROR;
        }

        $failed = $messages->failed;
        $io->writeln(0 === $failed->count->value
            ? 'failed: 0'
            : \sprintf('failed: %s, the oldest since %s', self::number($failed->count), $failed->oldestAt?->format(\DATE_ATOM) ?? 'unknown'));
        foreach ($messages->transports as $transport) {
            $io->writeln(self::line($transport));
        }

        return $messages->healthy ? Command::SUCCESS : self::UNHEALTHY;
    }

    private static function line(TransportStatus $transport): string
    {
        $line = \sprintf('%s: ready %s', $transport->name, self::number($transport->ready));
        if (null !== $transport->oldestReadyAgeSeconds) {
            $line .= \sprintf(', the oldest %d s', $transport->oldestReadyAgeSeconds);
        }

        return $transport->stalled ? $line.', STANDS' : $line;
    }

    private static function number(BoundedCount $count): string
    {
        return $count->value.($count->capped ? '+' : '');
    }
}
