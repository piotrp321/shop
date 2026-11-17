<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Catalog\CatalogLoader;
use App\DemoData\Profile\RunContextFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 1b of the generator: the products with their variants and category links, in parts of a fixed size. One process
 * writes the parts worker, worker + workers, …; bin/generate-demo starts one process per worker and awaits them all.
 * `--list-parts` only prints the number of parts of the profile. Needs demo-data:categories first.
 * Exit codes: 0 done, 1 refused (bad option, categories missing) or failed.
 */
#[AsCommand(name: 'demo-data:products', description: 'Writes the products, variants and category links of the generated catalog (step 1).')]
final readonly class ProductsCommand
{
    public function __construct(
        private RunContextFactory $contexts,
        private CatalogLoader $loader,
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
        if (!$this->loader->categoriesArePrepared($context)) {
            $io->error('The categories of the generator are missing: run demo-data:categories first.');

            return Command::FAILURE;
        }

        $done = 0;
        $total = $this->loader->loadProducts($context, $worker, $workers, static function (int $part, int $products, int $variants) use ($io, &$done): void {
            ++$done;
            if (0 === $done % 100) {
                $io->writeln(\sprintf('part %d: %d products, %d variants (%d parts done)', $part, $products, $variants, $done));
            }
        });
        $io->success(\sprintf('products (worker %d of %d): %d parts, %d products, %d variants', $worker, $workers, $total['parts'], $total['products'], $total['variants']));

        return Command::SUCCESS;
    }
}
