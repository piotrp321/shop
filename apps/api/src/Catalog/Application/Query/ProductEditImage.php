<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ProductEditImage
{
    public function __construct(
        public string $key,
        public string $alt,
        public ImageView $preview,
    ) {
    }
}
