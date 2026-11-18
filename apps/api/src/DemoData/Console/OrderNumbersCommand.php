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
 * Step 7 of the generator: the sequence of the order numbers past the highest number (the numbers of the generated
 * orders grow with their placement already; the next order placed in the shop gets the following one). Never moves the
 * sequence back. Takes the options of every step; the sequence does not depend on them.
 * Exit codes: 0 done, 1 refused (bad option).
 */
#[AsCommand(name: 'demo-data:order-numbers', description: 'Sets the sequence of the order numbers past the highest number (step 7).')]
final readonly class OrderNumbersCommand
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
            $this->contexts->create($demoProfile, $seed, $products, $anchor);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('the next order number follows R-%d', $this->loader->advanceOrderNumbers()));

        return Command::SUCCESS;
    }
}
