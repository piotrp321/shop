<?php

declare(strict_types=1);

namespace App\Catalog\Application\Demo;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Port\ProductImageStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creates the demo catalog through the application commands. plan() reads and validates everything first, so a
 * broken definition or a missing photo is found before any data is changed.
 */
final readonly class DemoCatalogLoader
{
    public function __construct(
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ProductImageStorage $imageStorage,
        #[Autowire('%kernel.project_dir%/fixtures/catalog.yaml')]
        private string $definitionPath,
        #[Autowire('%kernel.project_dir%/fixtures/images')]
        private string $imagesDir,
        private DemoCatalogExpander $expander = new DemoCatalogExpander(),
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the definition is invalid or a pooled photo is missing
     */
    public function plan(): DemoCatalog
    {
        $catalog = $this->expander->expand(DemoCatalogDefinition::fromFile($this->definitionPath));
        foreach ($this->files($catalog) as $file) {
            $path = $this->imagePath($file);
            if (!is_file($path) || !is_readable($path)) {
                throw new \InvalidArgumentException(\sprintf('The demo photo "%s" does not exist or is not readable.', $path));
            }
        }

        return $catalog;
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
        foreach ($this->files($catalog) as $file) {
            $contents = file_get_contents($this->imagePath($file));
            if (false === $contents) {
                throw new \RuntimeException(\sprintf('The demo photo "%s" could not be read.', $file));
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

    /**
     * @return list<string> the distinct photo files used by the products, in order of first use
     */
    private function files(DemoCatalog $catalog): array
    {
        $files = [];
        foreach ($catalog->products as $product) {
            foreach ($product->images as $image) {
                $files[$image['file']] = true;
            }
        }

        return array_map(strval(...), array_keys($files));
    }

    private function imagePath(string $file): string
    {
        return rtrim($this->imagesDir, '/').'/'.$file;
    }
}
