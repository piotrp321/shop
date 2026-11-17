<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Profile\RunContext;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Step 2 of the generator for the profile small: the files of fixtures/images are stored once in the bucket of the
 * product originals (Garage) under `originals/<sha256>.<extension>`, and every product gets the keys of the photo group
 * of its type. The other profiles take their photos from the pool (PhotoPoolLoader).
 *
 * Tables written: catalog_product (images).
 */
final readonly class PhotoLoader
{
    public function __construct(
        private Dictionaries $dictionaries,
        private ProductPhotos $products,
        #[Autowire(service: 'product_images.storage')]
        private FilesystemOperator $originals,
        #[Autowire('%kernel.project_dir%/fixtures/images')]
        private string $imagesDirectory,
    ) {
    }

    /**
     * @return array{files: int, products: int, photos: int}
     */
    public function load(RunContext $context): array
    {
        $keysByFile = $this->storeFiles();
        $groups = [];
        foreach ($this->dictionaries->photoGroupNames() as $group) {
            $groups[$group] = array_map(static fn (string $file): string => $keysByFile[$file], $this->dictionaries->photoFiles($group));
        }

        return ['files' => \count($keysByFile)] + $this->products->assign($context, $this->dictionaries, $groups);
    }

    /**
     * @return array<string, string> the key of every file of the photo groups, by file name
     */
    private function storeFiles(): array
    {
        $keys = [];
        foreach ($this->dictionaries->allPhotoFiles() as $file) {
            $path = rtrim($this->imagesDirectory, '/').'/'.$file;
            $contents = file_get_contents($path);
            if (false === $contents) {
                throw new \RuntimeException(\sprintf('The photo "%s" could not be read.', $path));
            }
            $key = ImageKeys::forContents($contents);
            if (!$this->originals->fileExists($key)) {
                $this->originals->write($key, $contents);
            }
            $keys[$file] = $key;
        }

        return $keys;
    }
}
