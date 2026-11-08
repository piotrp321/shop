<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * An upload of an original image from the panel.
 */
final readonly class StoreProductImage
{
    /**
     * @param string $contents the whole file
     */
    public function __construct(public string $contents)
    {
    }
}
