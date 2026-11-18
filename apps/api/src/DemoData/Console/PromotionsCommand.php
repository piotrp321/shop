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
 * Step 3b of the generator: the ended promotions with their variants and the ended historical discount codes (one
 * process; the codes have no usages, the orders make them). Needs demo-data:prices first (a variant of a promotion has a
 * price). Exit codes: 0 done, 1 refused (bad option) or failed.
 */
#[AsCommand(name: 'demo-data:promotions', description: 'Writes the ended promotions and the historical discount codes (step 3).')]
final readonly class PromotionsCommand
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
    ): int {
        try {
            $context = $this->contexts->create($demoProfile, $seed, $products, $anchor);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $total = $this->loader->loadPromotions($context);
        $io->success(\sprintf('promotions: %d promotions with %d variants, %d discount codes', $total['promotions'], $total['variants'], $total['codes']));

        return Command::SUCCESS;
    }
}
