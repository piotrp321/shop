<?php

declare(strict_types=1);

namespace App\Shared\UI\Console;

use App\Shared\Infrastructure\Messaging\FailedMessageQueue;
use App\Shared\Infrastructure\Messaging\KeepFailedMessageListener;
use App\Shared\Infrastructure\Messaging\KeptInFailureTransportStamp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;

/**
 * The bulk retry of `failed` (spec: docs/features/async-reliability/spec.md, D6, W12). It takes the ids of at most --max
 * oldest messages available in `failed` at the start and retries only them, one by one, with
 * `messenger:failed:retry <id> --force`: a message that fails again stays in `failed` under a new id
 * (KeepFailedMessageListener), so it waits for the next run instead of being retried again in a loop. The end of a run
 * does not mean an empty queue: the report says how many were selected, handled (and removed), kept after a failure
 * (with their new ids) and how many messages are left in `failed`, all of them outside the list of this run.
 *
 * No exactly-once: two runs at once can retry the same message, so its handler runs twice (handlers are idempotent).
 *
 * Exit codes: 0 the run is done (also when messages were kept after a failure); 1 an invalid --max (nothing retried);
 * 2 the queue could not be read, or a retry failed outside the handler (e.g. the copy could not be written): the
 * command prints the report so far and hands the error on to the console.
 */
#[AsCommand(name: 'app:messaging:failed-retry', description: 'Retries the oldest messages of `failed` once each; a message that fails again stays in `failed` under a new id.')]
final readonly class FailedRetryCommand
{
    public const int ERROR = 2;
    public const int MAX_LIMIT = 1000;

    public function __construct(
        private FailedMessageQueue $queue,
        #[Autowire(service: 'console.command.messenger_failed_messages_retry')]
        private Command $retry,
        #[Autowire(service: 'event_dispatcher')]
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Option(description: 'The most messages retried in this run (1 to 1000)')]
        string $max = '200',
    ): int {
        if (1 !== preg_match('/^[1-9][0-9]{0,3}$/D', $max) || (int) $max > self::MAX_LIMIT) {
            $io->error(\sprintf('--max must be a whole number from 1 to %d.', self::MAX_LIMIT));

            return Command::FAILURE;
        }
        $limit = (int) $max;

        try {
            $selected = $this->queue->oldestAvailableIds($limit);
        } catch (\Throwable $failure) {
            throw $this->readFailure($io, $failure);
        }
        $io->writeln(\sprintf('Selected: %d (the oldest messages in failed, at most %d)', \count($selected), $limit));

        $outcomes = [];
        try {
            foreach ($selected as $id) {
                $outcomes[$id] = $this->retryOne($id, $output);
            }
        } catch (\Throwable $failure) {
            $this->writeReport($io, $outcomes);
            $io->writeln('<error>The retry stopped: '.OutputFormatter::escape($failure->getMessage()).'</error>');

            throw new \RuntimeException('The retry of a message of failed failed outside its handler.', self::ERROR, $failure);
        }

        $this->writeReport($io, $outcomes);
        try {
            $left = $this->queue->count();
        } catch (\Throwable $failure) {
            throw $this->readFailure($io, $failure);
        }
        $io->writeln(\sprintf('Left in failed: %d', $left));
        if ($left > 0) {
            $io->writeln('More messages are left in failed (none of them was retried in this run): run the command again to retry them, or remove them with messenger:failed:remove.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return FailedRetryOutcome|null null when the message was removed meanwhile (by an operator or another run), so
     *                                 that messenger:failed:retry, which ends with an error on a missing id, is not run
     */
    private function retryOne(string $id, OutputInterface $output): ?FailedRetryOutcome
    {
        if (!$this->queue->contains($id)) {
            return null;
        }

        $outcome = new FailedRetryOutcome();
        $listeners = [
            WorkerMessageHandledEvent::class => [static function (WorkerMessageHandledEvent $event) use ($outcome): void {
                if (KeepFailedMessageListener::FAILURE_TRANSPORT === $event->getReceiverName()) {
                    $outcome->handled = true;
                }
            }, 0],
            // Dispatched by the retry listener of Messenger with the envelope it sent back to `failed` (a delayed retry).
            WorkerMessageRetriedEvent::class => [static function (WorkerMessageRetriedEvent $event) use ($outcome): void {
                if (KeepFailedMessageListener::FAILURE_TRANSPORT === $event->getReceiverName()) {
                    $outcome->newId = KeepFailedMessageListener::idOf($event->getEnvelope());
                    $outcome->delayed = true;
                }
            }, 0],
            // After every listener of Messenger and KeepFailedMessageListener.
            WorkerMessageFailedEvent::class => [static function (WorkerMessageFailedEvent $event) use ($outcome): void {
                if (KeepFailedMessageListener::FAILURE_TRANSPORT !== $event->getReceiverName()) {
                    return;
                }
                $outcome->failed = true;
                $kept = $event->getEnvelope()->last(KeptInFailureTransportStamp::class);
                if (null !== $kept) {
                    $outcome->newId = $kept->id;
                }
            }, -1000],
        ];
        foreach ($listeners as $event => [$listener, $priority]) {
            $this->dispatcher->addListener($event, $listener, $priority);
        }

        try {
            $input = new ArrayInput(['id' => [$id], '--force' => true, '--transport' => KeepFailedMessageListener::FAILURE_TRANSPORT]);
            $input->setInteractive(false);
            // Its own output (a block per message) only with -v; the report is the output of this command.
            $this->retry->run($input, $output->isVerbose() ? $output : new NullOutput());
        } finally {
            foreach ($listeners as $event => [$listener]) {
                $this->dispatcher->removeListener($event, $listener);
            }
        }

        return $outcome;
    }

    /**
     * @param array<string, FailedRetryOutcome|null> $outcomes by the id the message had when it was selected
     */
    private function writeReport(SymfonyStyle $io, array $outcomes): void
    {
        $kept = array_filter($outcomes, static fn (?FailedRetryOutcome $outcome): bool => null !== $outcome && $outcome->failed);
        $handled = array_filter($outcomes, static fn (?FailedRetryOutcome $outcome): bool => null !== $outcome && !$outcome->failed && $outcome->handled);
        $gone = \count($outcomes) - \count($kept) - \count($handled);
        $io->writeln(\sprintf('Handled and removed: %d', \count($handled)));
        $io->writeln(\sprintf('Kept in failed after a failure, under a new id: %d', \count($kept)));
        foreach ($kept as $id => $outcome) {
            $io->writeln(\sprintf(
                '  %s is now %s%s',
                $id,
                $outcome->newId ?? 'unknown',
                $outcome->delayed ? ' (retried by Messenger after a delay)' : '',
            ));
        }
        if ($gone > 0) {
            $io->writeln(\sprintf('Gone before the retry (removed meanwhile): %d', $gone));
        }
    }

    private function readFailure(SymfonyStyle $io, \Throwable $failure): \RuntimeException
    {
        // One line, not a block of SymfonyStyle (it wraps at about 74 characters in a pod).
        $io->writeln('<error>The messages of failed could not be read: '.OutputFormatter::escape($failure->getMessage()).'</error>');

        return new \RuntimeException('The messages of failed could not be read.', self::ERROR, $failure);
    }
}
