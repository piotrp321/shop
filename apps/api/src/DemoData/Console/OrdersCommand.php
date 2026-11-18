<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Orders\OrdersLoader;
use App\DemoData\Profile\RunContextFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 5 of the generator: the orders with their lines, history, payments and usages of the historical codes, in parts
 * of the fixed size of profiles.yaml (a part is a contiguous range of the global indexes of the orders). One process
 * writes the parts worker, worker + workers, …; bin/generate-demo starts one process per worker and awaits them all.
 * `--list-parts` only prints the number of parts of the profile. Needs the catalog, the prices and the codes (steps 1
 * and 3). Exit codes: 0 done, 1 refused (bad option, catalog or prices missing) or failed.
 */
#[AsCommand(name: 'demo-data:orders', description: 'Writes the generated orders with their history, payments and code usages (step 5).')]
final readonly class OrdersCommand
{
    public function __construct(
        private RunContextFactory $contexts,
        private OrdersLoader $loader,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Profile of profiles.yaml (not --profile, which the console itself reserves)')]
        string $demoProfile = 'small',
        #[Option(description: 'Reference moment (default: the start of the current UTC day)')]
        ?string $anchor = null,
        #[Option(description: 'Namespace of the seed (a UUID) instead of the one of profiles.yaml')]
        ?string $seed = null,
        #[Option(description: 'Number of products instead of the one of the profile')]
        ?int $products = null,
        #[Option(description: 'Number of this process, from 0')]
        int $worker = 0,
        #[Option(description: 'Number of processes that share the parts')]
        int $workers = 1,
        #[Option(description: 'Only print the number of parts of the profile')]
        bool $listParts = false,
    ): int {
        try {
            $context = $this->contexts->create($demoProfile, $seed, $products, $anchor);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ($workers < 1 || $worker < 0 || $worker >= $workers) {
            $io->error('--worker has to be from 0 to --workers - 1, and --workers at least 1.');

            return Command::FAILURE;
        }
        if ($listParts) {
            $io->writeln((string) $context->orderParts());

            return Command::SUCCESS;
        }

        $done = 0;
        try {
            $total = $this->loader->load($context, $worker, $workers, static function (int $part, int $orders, int $lines) use ($io, &$done): void {
                ++$done;
                if (0 === $done % 100) {
                    $io->writeln(\sprintf('part %d: %d orders, %d lines (%d parts done)', $part, $orders, $lines, $done));
                }
            });
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('orders (worker %d of %d): %d parts, %d orders, %d lines', $worker, $workers, $total['parts'], $total['orders'], $total['lines']));

        return Command::SUCCESS;
    }
}
