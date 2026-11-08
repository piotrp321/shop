<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class SitemapProductList
{
    /**
     * @param list<SitemapProduct> $items in the order of the product id
     */
    public function __construct(public array $items)
    {
    }
}
