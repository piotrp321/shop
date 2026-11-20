<?php

declare(strict_types=1);

namespace App\Shared\UI\Console;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Disables `messenger:failed:retry` without a message id (spec: docs/features/async-reliability/spec.md, D6, W12): it
 * takes message after message from `failed` until none is available, and a message that fails again stays there
 * available at once under a new id, so it would never end. The console ends the command with the code 113
 * (ConsoleCommandEvent::RETURN_CODE_DISABLED) before it runs. With ids it runs as it is, and so does
 * app:messaging:failed-retry, which calls it with an id.
 *
 * The console binds the input before this event and resolves an abbreviated name to the command, whose name is
 * compared here. Running the command class from PHP, outside the console application, is not covered.
 */
#[AsEventListener(event: ConsoleEvents::COMMAND)]
final readonly class DisableBulkFailedRetryListener
{
    private const string COMMAND = 'messenger:failed:retry';

    public function __invoke(ConsoleCommandEvent $event): void
    {
        if (self::COMMAND !== $event->getCommand()?->getName()) {
            return;
        }

        $input = $event->getInput();
        if ($input->hasArgument('id') && [] !== (array) $input->getArgument('id')) {
            return;
        }

        $event->disableCommand();
        $output = $event->getOutput();
        $output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $output->writeln('<error>messenger:failed:retry without a message id is disabled: a message that fails again stays in failed under a new id, so it would never end.</error>');
        $output->writeln(OutputFormatter::escape('Retry one message with messenger:failed:retry <id> --force, or the oldest ones once each with app:messaging:failed-retry [--max=200].'));
    }
}
