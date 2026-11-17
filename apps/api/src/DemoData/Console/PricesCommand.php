<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Pricing\PricingLoader;
use App\DemoData\Profile\RunContextFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 3a of the generator: the current price and the price history of every generated variant, in the parts of the
 * products step. One process writes the parts worker, worker + workers, …; bin/generate-demo starts one process per
 * worker and awaits them all. `--list-parts` only prints the number of parts of the profile. Needs demo-data:products
 * first; demo-data:promotions follows when every part is written.
 * Exit codes: 0 done, 1 refused (bad option, products missing) or failed.
 */
#[AsCommand(name: 'demo-data:prices', description: 'Writes the prices and the price history of the generated variants (step 3).')]
final readonly class PricesCommand
{
    public function __construct(
        private RunContextFactory $contexts,
        private PricingLoader $loader,
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
            $io->writeln((string) $context->productParts());

            return Command::SUCCESS;
        }

        $done = 0;
        try {
            $total = $this->loader->loadPrices($context, $worker, $workers, static function (int $part, int $variants, int $entries) use ($io, &$done): void {
                ++$done;
                if (0 === $done % 100) {
                    $io->writeln(\sprintf('part %d: %d variants, %d history entries (%d parts done)', $part, $variants, $entries, $done));
                }
            });
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('prices (worker %d of %d): %d parts, %d variants, %d history entries', $worker, $workers, $total['parts'], $total['variants'], $total['entries']));

        return Command::SUCCESS;
    }
}
