<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Copy\CopyWriter;
use App\DemoData\Profile\RunContext;
use App\DemoData\Random\Prng;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Step 2 of the generator for the profile small: the files of fixtures/images are stored once in the bucket of the
 * product originals (Garage) under `originals/<sha256>.<extension>`, and every product gets the keys of the photo group
 * of its type. The pool of the other profiles is DEMO-DATA-6.
 *
 * Tables written: catalog_product (images).
 */
final readonly class PhotoLoader
{
    public function __construct(
        private Connection $connection,
        private CopyWriter $copy,
        private Dictionaries $dictionaries,
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

        $generator = new ProductGenerator($this->dictionaries, $context->namespace, $context->anchor, $context->profile->products, $context->partSize);
        $products = 0;
        $photos = 0;
        for ($part = 0; $part < $context->productParts(); ++$part) {
            $prng = Prng::forPart($context->namespace, PhotoAssigner::STREAM, $part);
            $rows = [];
            foreach ($generator->part($part) as $spec) {
                $images = PhotoAssigner::assign($spec, $groups[$spec->photoGroup], $prng);
                $photos += \count($images);
                $rows[] = [$spec->id, json_encode($images, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)];
            }
            $products += $this->connection->transactional(fn (): int => $this->update($rows));
        }

        return ['files' => \count($keysByFile), 'products' => $products, 'photos' => $photos];
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

    /**
     * @param list<list<mixed>> $rows product id and the JSON of its images
     */
    private function update(array $rows): int
    {
        $this->copy->relaxDurability();
        $this->connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS demo_product_images (id uuid PRIMARY KEY, images jsonb NOT NULL)');
        $this->connection->executeStatement('TRUNCATE demo_product_images');
        $this->copy->copy('demo_product_images', ['id', 'images'], $rows);
        $updated = (int) $this->connection->executeStatement('UPDATE catalog_product p SET images = t.images FROM demo_product_images t WHERE p.id = t.id');
        if ($updated !== \count($rows)) {
            throw new \RuntimeException(\sprintf('%d of %d products are missing: run the products step first.', \count($rows) - $updated, \count($rows)));
        }

        return $updated;
    }
}
