<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Copy\CopyWriter;
use App\DemoData\Profile\RunContext;
use App\DemoData\Random\Prng;
use Doctrine\DBAL\Connection;

/**
 * Gives every generated product its photos: the keys of the photo group of its type (PhotoAssigner), part by part, one
 * transaction per part. The keys are the ones of the originals already stored in the bucket.
 *
 * Tables written: catalog_product (images).
 */
final readonly class ProductPhotos
{
    public function __construct(
        private Connection $connection,
        private CopyWriter $copy,
    ) {
    }

    /**
     * @param array<string, non-empty-list<string>> $keysByGroup the keys of the originals of every photo group
     *
     * @return array{products: int, photos: int}
     */
    public function assign(RunContext $context, Dictionaries $dictionaries, array $keysByGroup): array
    {
        $generator = new ProductGenerator($dictionaries, $context->namespace, $context->anchor, $context->profile->products, $context->partSize);
        $products = 0;
        $photos = 0;
        for ($part = 0; $part < $context->productParts(); ++$part) {
            $prng = Prng::forPart($context->namespace, PhotoAssigner::STREAM, $part);
            $rows = [];
            foreach ($generator->part($part) as $spec) {
                $images = PhotoAssigner::assign($spec, $keysByGroup[$spec->photoGroup], $prng);
                $photos += \count($images);
                $rows[] = [$spec->id, json_encode($images, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)];
            }
            $products += $this->connection->transactional(fn (): int => $this->update($rows));
        }

        return ['products' => $products, 'photos' => $photos];
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
