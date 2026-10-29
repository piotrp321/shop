<?php

declare(strict_types=1);

namespace App\Catalog\Application\Demo;

final readonly class DemoCatalog
{
    /**
     * @param list<DemoCategory>    $categories a parent always comes before its children
     * @param list<DemoProduct>     $products   in expansion order
     * @param array<string, string> $photoPaths photo file name => path of a readable file, for every file the products use
     */
    public function __construct(
        public array $categories,
        public array $products,
        public array $photoPaths,
    ) {
        foreach ($products as $product) {
            foreach ($product->images as $image) {
                if (!isset($photoPaths[$image['file']])) {
                    throw new \InvalidArgumentException(\sprintf('No path for the photo "%s" of the product "%s".', $image['file'], $product->slug));
                }
            }
        }
    }
}
