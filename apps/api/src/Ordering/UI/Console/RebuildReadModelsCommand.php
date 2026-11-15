<?php

declare(strict_types=1);

namespace App\Ordering\UI\Console;

use App\Ordering\Application\ReadModel\SalesRebuild;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the read models of Ordering from the orders: the hourly sales of the dashboard, hour by hour, each hour in
 * a transaction of its own and correct while payments come in (SalesRebuild). Idempotent: a second run leaves the same
 * rows. The first fill after the rollout is the step of app:backfill; run it by hand after orders were written past
 * the commands.
 * Exit codes: 0 rebuilt, 1 stopped by an error (the hours rebuilt before it stay rebuilt; a new run starts again).
 */
#[AsCommand(name: 'ordering:read-models:rebuild', description: 'Rebuilds the read models of the orders (the hourly sales) from the orders.')]
final readonly class RebuildReadModelsCommand
{
    public function __construct(private SalesRebuild $sales)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $summary = $this->sales->run($io->writeln(...));
        } catch (\Throwable $failure) {
            $io->error(\sprintf('The rebuild stopped: %s. The hours rebuilt before stay rebuilt; run it again.', $failure->getMessage()));

            return Command::FAILURE;
        }
        $io->success($summary);

        return Command::SUCCESS;
    }
}
