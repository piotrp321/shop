<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

interface CatalogStatistics
{
    /**
     * Fails with the exception of the database when it is unreachable: without the catalogue there is no dashboard.
     *
     * @param bool $withoutImages whether the products without images are counted too
     */
    public function counts(bool $withoutImages): CatalogCounts;
}
