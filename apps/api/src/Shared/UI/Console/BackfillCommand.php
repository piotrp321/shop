<?php

declare(strict_types=1);

namespace App\Shared\UI\Console;

use App\Shared\Infrastructure\Backfill\BackfillLog;
use App\Shared\Infrastructure\Backfill\BackfillStep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Fills the data of the migrations after the rollout of the new version (the Job `backfill` of the chart, locally the
 * end of bin/seed-demo): runs the registered BackfillSteps in their order and records each completed one in
 * app_backfill, so later runs skip it; --force runs every step again. A failing step is not recorded and stops the
 * run, the steps after it wait for the next one.
 * Exit codes: 0 every step completed (or was skipped), 1 a step failed (the command prints a message and hands the
 * error on to the console) or two steps share a name (nothing ran).
 */
#[AsCommand(name: 'app:backfill', description: 'Runs the backfill steps of the migrations that have not completed yet.')]
final readonly class BackfillCommand
{
    /**
     * @param iterable<BackfillStep> $steps
     */
    public function __construct(
        #[AutowireIterator(BackfillStep::TAG)]
        private iterable $steps,
        private BackfillLog $log,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Run the steps that completed before too')]
        bool $force = false,
    ): int {
        $steps = [];
        foreach ($this->steps as $step) {
            if (isset($steps[$step->name()])) {
                $io->error(\sprintf('Two backfill steps are named "%s"; nothing was run.', $step->name()));

                return Command::FAILURE;
            }
            $steps[$step->name()] = $step;
        }

        $completed = $this->log->completed();
        foreach ($steps as $name => $step) {
            if (!$force && isset($completed[$name])) {
                $io->writeln(\sprintf('%s: skipped, completed at %s', $name, $completed[$name]));
                continue;
            }

            $io->writeln(\sprintf('%s: running', $name));
            try {
                $step->run(static function (string $line) use ($io, $name): void {
                    $io->writeln(\sprintf('%s: %s', $name, $line));
                });
            } catch (\Throwable $e) {
                $io->error(\sprintf('%s: failed: %s. The steps after it did not run.', $name, $e->getMessage()));

                // Handed on, not swallowed: the console reports it (a log with the exception, an event of Sentry) and ends with 1.
                throw new \RuntimeException(\sprintf('The backfill step %s failed: %s', $name, $e->getMessage()), Command::FAILURE, $e);
            }
            $this->log->record($name);
            $io->writeln(\sprintf('%s: completed', $name));
        }

        $io->success('Backfill complete.');

        return Command::SUCCESS;
    }
}
