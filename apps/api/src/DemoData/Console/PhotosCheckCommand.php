<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Photo\InvalidPhotoManifest;
use App\DemoData\Photo\InvalidPhotoPool;
use App\DemoData\Photo\PhotoManifest;
use App\DemoData\Photo\PhotoPoolVerifier;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The check bin/upload-demo-photos runs in the api container (PHP runs only there) before it sends anything: the manifest
 * against its schema and the photo groups, the directory against the manifest (the complete set of files, the SHA-256 of
 * each, the rules of the upload of Catalog), and with --archive the tar archive of the files in the order of the manifest.
 * The last line, OBJECT_KEY=<key>, is the key the archive goes to. Exit codes: 0 passes, 1 refused (every violation is listed).
 */
#[AsCommand(name: 'demo-data:photos:check', description: 'Checks a directory of photos against the manifest and optionally packs them for the upload.')]
final readonly class PhotosCheckCommand
{
    public function __construct(
        private PhotoPoolVerifier $verifier,
        private Dictionaries $dictionaries,
        #[Autowire('%kernel.project_dir%/fixtures/demo-data/photos.yaml')]
        private string $defaultManifest,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The directory of the photos (every file of the manifest and nothing else)')]
        string $directory,
        #[Option(description: 'The manifest (default: fixtures/demo-data/photos.yaml)')]
        ?string $manifest = null,
        #[Option(description: 'Where to write the tar archive of the checked photos (a path ending in .tar)')]
        ?string $archive = null,
    ): int {
        if (null !== $archive && !str_ends_with($archive, '.tar')) {
            $io->error('The archive has to be a path ending in .tar.');

            return Command::FAILURE;
        }

        try {
            $groups = $this->dictionaries->photoGroupNames();
            $parsed = PhotoManifest::fromFile($manifest ?? $this->defaultManifest, $groups);
            $missing = $parsed->groupsWithoutFiles($groups);
            if ([] !== $missing) {
                throw InvalidPhotoManifest::because('no files in the manifest: '.implode(', ', $missing));
            }
            $this->verifier->verify($directory, $parsed);
        } catch (InvalidPhotoManifest|InvalidPhotoPool $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (null !== $archive) {
            $this->pack($directory, $archive, $parsed);
        }
        $io->success(\sprintf('%d files of the manifest checked.', \count($parsed->files)));
        $io->writeln('OBJECT_KEY='.$parsed->key);

        return Command::SUCCESS;
    }

    private function pack(string $directory, string $archive, PhotoManifest $manifest): void
    {
        is_file($archive) && unlink($archive);
        $tar = new \PharData($archive);
        // One call: addFile() in a loop rewrites the archive for every file (quadratic), buildFromIterator() writes it once.
        $files = [];
        foreach ($manifest->fileNames() as $name) {
            $files[$name] = rtrim($directory, '/').'/'.$name;
        }
        $tar->buildFromIterator(new \ArrayIterator($files));
        unset($tar);
    }
}
