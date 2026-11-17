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
 * Step 1a of the generator: the categories of the tail (the departments of the seed catalog are reused). Written once
 * before the parts of the products. Adds rows only, so running it again changes nothing.
 * Exit codes: 0 done, 1 refused (bad option) or failed.
 */
#[AsCommand(name: 'demo-data:categories', description: 'Writes the categories of the generated catalog (step 1, run once before demo-data:products).')]
final readonly class CategoriesCommand
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
    ): int {
        try {
            $context = $this->contexts->create($demoProfile, $seed, $products, $anchor);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $report = $this->loader->prepareCategories($context);
        $io->success(\sprintf('categories: %d written, %d already there', $report['created'], $report['existing']));

        return Command::SUCCESS;
    }
}
