<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Profile\RunContextFactory;
use App\DemoData\Verify\CatalogVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The consistency check after the load (the last step of bin/generate-demo, also on its own). For now the catalog
 * checks: a published product without a variant, the counts of the tables against the profile, the slugs of the tail
 * (unique, different from the slugs of fixtures/catalog.yaml).
 * Exit codes: 0 no violation, 1 violations (listed) or a bad option.
 */
#[AsCommand(name: 'demo-data:verify', description: 'Checks that the generated data is consistent; lists the violations.')]
final readonly class VerifyCommand
{
    public function __construct(
        private RunContextFactory $contexts,
        private CatalogVerifier $catalog,
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

        $violations = $this->catalog->violations($context);
        if ([] !== $violations) {
            $io->error(\sprintf('%d violation(s):', \count($violations)));
            $io->listing($violations);

            return Command::FAILURE;
        }
        $io->success('the generated data is consistent');

        return Command::SUCCESS;
    }
}
