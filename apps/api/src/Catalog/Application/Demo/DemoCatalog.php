<?php

declare(strict_types=1);

namespace App\Catalog\Application\Demo;

final readonly class DemoCatalog
{
    /**
     * @param list<DemoCategory> $categories a parent always comes before its children
     * @param list<DemoProduct>  $products   in expansion order
     */
    public function __construct(
        public array $categories,
        public array $products,
    ) {
    }
}
