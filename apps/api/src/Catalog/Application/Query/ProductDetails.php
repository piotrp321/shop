<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * Everything the product page shows apart from the price and the stock (those come from the offer of the product).
 */
final readonly class ProductDetails
{
    /**
     * @param list<BreadcrumbItem>        $breadcrumb  categories from the root to the main category of the product
     * @param list<ImageView>             $images      all the images in position order, in the `product` size
     * @param list<string>                $variantAxes names of the axes, in order
     * @param list<ProductDetailsVariant> $variants    in position order
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $shortDescription,
        public array $breadcrumb,
        public array $images,
        public array $variantAxes,
        public array $variants,
    ) {
    }
}
