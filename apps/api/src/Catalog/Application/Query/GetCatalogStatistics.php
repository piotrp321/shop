<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class GetCatalogStatistics
{
    /**
     * @param bool $withoutImages whether the queue of the products without images is wanted; the caller decides by the
     *                            permission of the user (managing products)
     */
    public function __construct(public bool $withoutImages)
    {
    }
}
