<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Application\Port\DemoCatalogSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The demo catalog from apps/api/fixtures/catalog.yaml and the photos in apps/api/fixtures/images.
 */
final readonly class YamlDemoCatalogSource implements DemoCatalogSource
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/catalog.yaml')]
        private string $definitionPath,
        #[Autowire('%kernel.project_dir%/fixtures/images')]
        private string $imagesDir,
        private DemoCatalogExpander $expander = new DemoCatalogExpander(),
    ) {
    }

    public function load(): DemoCatalog
    {
        $catalog = $this->expander->expand(DemoCatalogDefinition::fromFile($this->definitionPath));

        $paths = [];
        foreach ($catalog->products as $product) {
            foreach ($product->images as $image) {
                $file = $image['file'];
                if (isset($paths[$file])) {
                    continue;
                }
                $path = rtrim($this->imagesDir, '/').'/'.$file;
                if (!is_file($path) || !is_readable($path)) {
                    throw new \InvalidArgumentException(\sprintf('The demo photo "%s" does not exist or is not readable.', $path));
                }
                $paths[$file] = $path;
            }
        }

        return new DemoCatalog($catalog->categories, $catalog->products, $paths);
    }
}
