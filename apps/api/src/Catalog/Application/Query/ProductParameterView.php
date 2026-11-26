<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * A parameter of a product as the page of the store and the edit form show it.
 */
final readonly class ProductParameterView
{
    public function __construct(
        public string $name,
        public string $value,
    ) {
    }
}
