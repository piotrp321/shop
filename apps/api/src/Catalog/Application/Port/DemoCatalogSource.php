<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Demo\DemoCatalog;

interface DemoCatalogSource
{
    /**
     * Reads and validates the whole demo catalog, including that every photo file it uses is readable.
     * Changes no data, so a broken definition is found before the catalog is reset.
     *
     * @throws \InvalidArgumentException when the definition is invalid or a photo is missing
     */
    public function load(): DemoCatalog;
}
