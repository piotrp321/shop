<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Query\ImageView;

final readonly class StoredProductImage
{
    /**
     * @param string    $key     the key of the original, to be named by a product write
     * @param ImageView $preview the original in the `thumbnail` size (without a meaningful alt text)
     */
    public function __construct(
        public string $key,
        public ImageView $preview,
    ) {
    }
}
