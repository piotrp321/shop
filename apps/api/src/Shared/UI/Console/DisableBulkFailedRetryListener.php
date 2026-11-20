<?php

declare(strict_types=1);

namespace App\Shared\UI\Console;

use App\Shared\Infrastructure\Messaging\KeepFailedMessageListener;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Disables the bulk retries of `failed` that would never end (spec: docs/features/async-reliability/spec.md, D6, W12):
 * a message that fails again stays in `failed` available at once under a new id (KeepFailedMessageListener), so a
 * command that takes message after message from `failed` takes it again and again. The console ends such a command
 * with the code 113 (ConsoleCommandEvent::RETURN_CODE_DISABLED) before it runs:
 *
 * - `messenger:failed:retry` without a message id (it takes messages until none is available); with ids it runs, and so
 *   does app:messaging:failed-retry, which calls it with an id;
 * - `messenger:consume` that would consume `failed`, read the way Messenger v8.1 reads its input: every receiver is a
 *   pattern `{^receiver$}` over the names of the transports, and a receiver no pattern matches is taken as a name of
 *   the receiver locator, which also knows the service id `messenger.transport.failed`; `--all` takes every transport,
 *   whatever receivers are named, minus the exact names of `--exclude-receivers`. Without receivers and without
 *   `--all` the command asks for them after this event, so it is disabled too: the receivers must be named.
 *
 * The console binds the input before this event and resolves an abbreviated name to the command, whose name is
 * compared here. Running the command classes from PHP, outside the console application, is not covered.
 */
#[AsEventListener(event: ConsoleEvents::COMMAND)]
final readonly class DisableBulkFailedRetryListener
{
    private const string FAILURE_TRANSPORT = KeepFailedMessageListener::FAILURE_TRANSPORT;
    private const string FAILURE_TRANSPORT_SERVICE = 'messenger.transport.'.self::FAILURE_TRANSPORT;

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $reason = match ($event->getCommand()?->getName()) {
            'messenger:failed:retry' => self::retriesWithoutId($event->getInput())
                ? 'messenger:failed:retry without a message id is disabled: a message that fails again stays in failed under a new id, so it would never end.'
                : null,
            'messenger:consume' => self::consumesFailed($event->getInput())
                ? 'messenger:consume is disabled when it may consume failed (named, matched by a pattern, through --all without --exclude-receivers=failed, or receivers not named): a message that fails again stays in failed under a new id, so it would be handled again and again. Name the receivers, without failed.'
                : null,
            default => null,
        };
        if (null === $reason) {
            return;
        }

        $event->disableCommand();
        $output = $event->getOutput();
        $output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $output->writeln('<error>'.OutputFormatter::escape($reason).'</error>');
        $output->writeln(OutputFormatter::escape('Retry one message of failed with messenger:failed:retry <id> --force, or the oldest ones once each with app:messaging:failed-retry [--max=200].'));
    }

    private static function retriesWithoutId(InputInterface $input): bool
    {
        return !$input->hasArgument('id') || [] === self::strings($input->getArgument('id'));
    }

    private static function consumesFailed(InputInterface $input): bool
    {
        if ($input->hasOption('all') && true === $input->getOption('all')) {
            $excluded = $input->hasOption('exclude-receivers') ? self::strings($input->getOption('exclude-receivers')) : [];

            return !\in_array(self::FAILURE_TRANSPORT, $excluded, true);
        }

        $receivers = $input->hasArgument('receivers') ? self::strings($input->getArgument('receivers')) : [];
        if ([] === $receivers) {
            return true;
        }

        foreach ($receivers as $receiver) {
            // As Messenger matches it (an invalid pattern matches nothing there either).
            if (self::FAILURE_TRANSPORT_SERVICE === $receiver || 1 === @preg_match(\sprintf('{^%s$}', $receiver), self::FAILURE_TRANSPORT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(\is_array($value) ? $value : [$value], \is_string(...)));
    }
}
