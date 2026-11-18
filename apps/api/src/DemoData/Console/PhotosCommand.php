<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Photo\PhotoLoader;
use App\DemoData\Photo\PhotoPoolLoader;
use App\DemoData\Profile\RunContextFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 2 of the generator: the photos of the products. The profile small stores the files of fixtures/images once in
 * the bucket of the originals and gives each product the keys of the photo group of its type; the other profiles take
 * the photo pool from the private bucket (DEMO_PHOTOS_* variables, fixtures/demo-data/photos.yaml; PhotoPoolLoader). With
 * --preflight nothing is changed: it only checks what the step needs (the variables, the manifest, the archive in the
 * bucket), which bin/generate-demo does before it deletes any data. Needs demo-data:products first.
 * Exit codes: 0 done, 1 refused (bad option, missing variables or manifest, a pool that does not match its manifest) or failed.
 */
#[AsCommand(name: 'demo-data:photos', description: 'Stores the photos in the bucket and assigns them to the generated products (step 2).')]
final readonly class PhotosCommand
{
    public function __construct(
        private RunContextFactory $contexts,
        private PhotoLoader $loader,
        private PhotoPoolLoader $pool,
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
        #[Option(description: 'Only check what the step needs (not for small), change nothing')]
        bool $preflight = false,
    ): int {
        try {
            $context = $this->contexts->create($demoProfile, $seed, $products, $anchor);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $small = 'small' === $context->profile->name;

        try {
            if ($preflight) {
                if (!$small) {
                    $manifest = $this->pool->preflight(true);
                    $io->success(\sprintf('photos: the pool version %d (%d files) can be loaded for the profile "%s"', $manifest->version, \count($manifest->files), $context->profile->name));
                } else {
                    $io->success('photos: the profile "small" uses the files of fixtures/images, nothing to check');
                }

                return Command::SUCCESS;
            }
            $report = $small ? $this->loader->load($context) : $this->pool->load($context);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('photos: %d files in the bucket, %d products, %d photos assigned', $report['files'], $report['products'], $report['photos']));

        return Command::SUCCESS;
    }
}
