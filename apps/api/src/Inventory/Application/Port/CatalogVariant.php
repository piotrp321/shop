<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\VariantId;

/**
 * A variant of Catalog as the demo stock needs it: its id and key.
 */
final readonly class CatalogVariant
{
    /**
     * @param string $key `<product slug>/<label slug>` (`<product slug>/standard` for the default variant), matched by
     *                    the demo stock patterns
     */
    public function __construct(
        public VariantId $id,
        public string $key,
    ) {
    }
}
