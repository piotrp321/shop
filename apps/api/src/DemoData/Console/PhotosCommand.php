<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Photo\PhotoLoader;
use App\DemoData\Profile\RunContextFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 2 of the generator: the photos of the products. The profile small stores the files of fixtures/images once in
 * the bucket of the originals and gives each product the keys of the photo group of its type; the other profiles need
 * the pool of photos (DEMO-DATA-6) and are refused for now. Needs demo-data:products first.
 * Exit codes: 0 done, 1 refused (bad option, profile without a pool) or failed.
 */
#[AsCommand(name: 'demo-data:photos', description: 'Stores the photos in the bucket and assigns them to the generated products (step 2).')]
final readonly class PhotosCommand
{
    public function __construct(
        private RunContextFactory $contexts,
        private PhotoLoader $loader,
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
        if ('small' !== $context->profile->name) {
            $io->error(\sprintf('The profile "%s" takes its photos from the pool, which DEMO-DATA-6 downloads; only "small" is supported here. Nothing was changed.', $context->profile->name));

            return Command::FAILURE;
        }

        try {
            $report = $this->loader->load($context);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('photos: %d files in the bucket, %d products, %d photos assigned', $report['files'], $report['products'], $report['photos']));

        return Command::SUCCESS;
    }
}
