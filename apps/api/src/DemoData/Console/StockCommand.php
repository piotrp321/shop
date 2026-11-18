<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Orders\StockLoader;
use App\DemoData\Profile\RunContextFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 6 of the generator: the stock of every variant without a stock record, the reservations and the stock movements
 * of the orders of the last 90 days, as one pass of SQL over the loaded orders (one process, after every part of the
 * orders). Exit codes: 0 done, 1 refused (bad option).
 */
#[AsCommand(name: 'demo-data:stock', description: 'Writes the stock, the reservations and the stock movements after the generated orders (step 6).')]
final readonly class StockCommand
{
    public function __construct(
        private RunContextFactory $contexts,
        private StockLoader $loader,
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
    ): int {
        try {
            $context = $this->contexts->create($demoProfile, $seed, $products, $anchor);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $total = $this->loader->load($context);
        $io->success(\sprintf('stock: %d stock records, %d reservations, %d movements', $total['items'], $total['reservations'], $total['movements']));

        return Command::SUCCESS;
    }
}
