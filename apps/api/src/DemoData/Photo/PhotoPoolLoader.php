<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Profile\RunContext;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Step 2 of the generator for the profiles other than small: the photos come from the pool, the archive
 * `demo-photos/v<N>/photos.tar` of the private bucket named by fixtures/demo-data/photos.yaml (spec: "Zdjęcia").
 *
 * The order is what keeps a bad pool from changing anything: the variables, the manifest and its photo groups are
 * checked first; the archive is downloaded to a work directory and unpacked there (only the files of the manifest, nothing
 * outside the directory); the complete set of files, the SHA-256 of each and the rules of the upload of Catalog are
 * checked next; only then each file is stored once in the bucket of the originals under `originals/<sha256>.<extension>`
 * (one that is already there is skipped) and the products get the keys of the group of their type. The work directory is
 * removed on every ending. The messages never repeat a value of the DEMO_PHOTOS_* variables or the message of the exception
 * of the client.
 *
 * Tables written: catalog_product (images).
 */
final readonly class PhotoPoolLoader
{
    public function __construct(
        private ProductPhotos $products,
        private Dictionaries $dictionaries,
        private PhotoRules $rules,
        private PhotoSourceAccess $access,
        #[Autowire(service: 'product_images.storage')]
        private FilesystemOperator $originals,
        #[Autowire(service: 'demo_photos.storage')]
        private FilesystemOperator $source,
        #[Autowire('%kernel.project_dir%/fixtures/demo-data/photos.yaml')]
        private string $manifestPath,
        private ?string $workDirectory = null,
    ) {
    }

    /**
     * What a run needs before it touches anything: the variables, the manifest that matches the photo groups and, with
     * $checkArchive, the archive in the bucket (a request that reads no file).
     *
     * @throws \RuntimeException with a message that names what is missing
     */
    public function preflight(bool $checkArchive = false): PhotoManifest
    {
        $missing = $this->access->missing();
        if ([] !== $missing) {
            throw new \RuntimeException(\sprintf('The photo pool of the private bucket needs these variables, and they are empty: %s. Nothing was changed.', implode(', ', $missing)));
        }
        if (!is_file($this->manifestPath)) {
            throw new \RuntimeException('There is no photo manifest (fixtures/demo-data/photos.yaml): upload a pool with bin/upload-demo-photos and commit the manifest. Nothing was changed.');
        }

        $groups = $this->dictionaries->photoGroupNames();
        $manifest = PhotoManifest::fromFile($this->manifestPath, $groups);
        $withoutFiles = $manifest->groupsWithoutFiles($groups);
        if ([] !== $withoutFiles) {
            throw InvalidPhotoManifest::because('no files in the manifest for the photo groups: '.implode(', ', $withoutFiles));
        }
        if ($checkArchive) {
            try {
                $exists = $this->source->fileExists($manifest->key);
            } catch (\Throwable $e) {
                throw PhotoSourceUnavailable::because($e, $manifest->key);
            }
            if (!$exists) {
                throw PhotoSourceUnavailable::missing($manifest->key);
            }
        }

        return $manifest;
    }

    /**
     * @return array{files: int, products: int, photos: int}
     *
     * @throws \RuntimeException
     */
    public function load(RunContext $context): array
    {
        $manifest = $this->preflight();

        $work = $this->createWorkDirectory();
        try {
            $this->download($manifest->key, $work.'/photos.tar');
            mkdir($work.'/files', 0o700);
            new TarExtractor($this->rules->maxBytes)->extract($work.'/photos.tar', $work.'/files', $manifest->fileNames());
            unlink($work.'/photos.tar');
            new PhotoPoolVerifier($this->rules)->verify($work.'/files', $manifest);

            $keysByFile = $this->store($work.'/files', $manifest);
            $keysByGroup = [];
            foreach ($manifest->fileNamesByGroup() as $group => $files) {
                $keysByGroup[$group] = array_map(static fn (string $file): string => $keysByFile[$file], $files);
            }

            return ['files' => \count($keysByFile)] + $this->products->assign($context, $this->dictionaries, $keysByGroup);
        } finally {
            self::remove($work);
        }
    }

    private function download(string $key, string $path): void
    {
        $target = fopen($path, 'w');
        if (false === $target) {
            throw new \RuntimeException('The work directory of the photo pool is not writable.');
        }
        try {
            $stream = $this->source->readStream($key);
            if (!\is_resource($stream) || false === stream_copy_to_stream($stream, $target)) {
                throw new \UnexpectedValueException('The archive could not be copied.');
            }
            fclose($stream);
        } catch (\Throwable $e) {
            throw PhotoSourceUnavailable::because($e, $key);
        } finally {
            fclose($target);
        }
    }

    /**
     * Every file once, one at a time (the pool is never in memory): the key is that of the upload in Catalog, a file that
     * is in the bucket already is not written again.
     *
     * @return array<string, string> the key of every file, by file name
     */
    private function store(string $directory, PhotoManifest $manifest): array
    {
        $keys = [];
        foreach ($manifest->fileNames() as $name) {
            $contents = file_get_contents($directory.'/'.$name);
            if (false === $contents) {
                throw new \RuntimeException(\sprintf('The photo "%s" could not be read from the work directory.', $name));
            }
            $key = ImageKeys::forContents($contents);
            try {
                if (!$this->originals->fileExists($key)) {
                    $this->originals->write($key, $contents);
                }
            } catch (FilesystemException $e) {
                throw new \RuntimeException(\sprintf('The photo "%s" could not be stored in the bucket of the originals: %s', $name, $e->getMessage()), 0, $e);
            }
            $keys[$name] = $key;
        }

        return $keys;
    }

    private function createWorkDirectory(): string
    {
        $path = rtrim($this->workDirectory ?? sys_get_temp_dir(), '/').'/demo-photos-'.bin2hex(random_bytes(8));
        if (!mkdir($path, 0o700, true) && !is_dir($path)) {
            throw new \RuntimeException('The work directory of the photo pool could not be created.');
        }

        return $path;
    }

    private static function remove(string $path): void
    {
        foreach (scandir($path) ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            is_dir($path.'/'.$name) && !is_link($path.'/'.$name) ? self::remove($path.'/'.$name) : @unlink($path.'/'.$name);
        }
        @rmdir($path);
    }
}
