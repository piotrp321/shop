<?php

declare(strict_types=1);

namespace App\Ordering\UI\Console;

use App\Ordering\Application\ReadModel\CustomerRebuild;
use App\Ordering\Application\ReadModel\SalesRebuild;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the read models of Ordering from the orders: the hourly sales of the dashboard, hour by hour (SalesRebuild),
 * then the customers of the panel, in batches of customers (CustomerRebuild); each hour and each batch in a
 * transaction of its own, correct while orders are placed and paid. Idempotent: a second run leaves the same rows.
 * The first fill after the rollout is the steps of app:backfill; run it by hand after orders were written past the
 * commands.
 * Exit codes: 0 rebuilt, 1 stopped by an error (the command prints a message and hands the error on to the console;
 * the hours and batches rebuilt before it stay rebuilt; a new run starts again).
 */
#[AsCommand(name: 'ordering:read-models:rebuild', description: 'Rebuilds the read models of the orders (the hourly sales and the customers) from the orders.')]
final readonly class RebuildReadModelsCommand
{
    public function __construct(private SalesRebuild $sales, private CustomerRebuild $customers)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $summary = [$this->sales->run($io->writeln(...))];
            $summary[] = $this->customers->run($io->writeln(...));
        } catch (\Throwable $failure) {
            $io->error(\sprintf('The rebuild stopped: %s. What was rebuilt before stays rebuilt; run it again.', $failure->getMessage()));

            // Handed on, not swallowed: the console reports it (a log with the exception, an event of Sentry) and ends with 1.
            throw new \RuntimeException($failure->getMessage(), Command::FAILURE, $failure);
        }
        $io->success($summary);

        return Command::SUCCESS;
    }
}
