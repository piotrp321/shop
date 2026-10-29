<?php

declare(strict_types=1);

namespace App\Catalog\Application\Demo;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Port\ProductImageStorage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creates a demo catalog through the application commands (the `command.bus`, injected by the parameter name).
 */
final readonly class DemoCatalogLoader
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private ProductImageStorage $imageStorage,
    ) {
    }

    /**
     * Categories first (parents before children), then the photos (each file stored once), then the products.
     */
    public function load(DemoCatalog $catalog): void
    {
        foreach ($catalog->categories as $category) {
            $this->commandBus->dispatch(new CreateCategory($category->id, $category->name, $category->parentId));
        }

        $keys = [];
        foreach ($catalog->photoPaths as $file => $path) {
            $contents = file_get_contents($path);
            if (false === $contents) {
                throw new \RuntimeException(\sprintf('The demo photo "%s" could not be read.', $path));
            }
            $keys[$file] = $this->imageStorage->store($contents);
        }

        foreach ($catalog->products as $product) {
            $this->commandBus->dispatch(new CreateProduct(
                $product->id,
                $product->name,
                $product->slug,
                $product->shortDescription,
                $product->mainCategoryId,
                $product->additionalCategoryIds,
                $product->taxCategory,
                array_map(static fn (array $image): array => ['key' => $keys[$image['file']], 'alt' => $image['alt']], $product->images),
            ));
        }
    }
}
