<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

interface CatalogStatisticsProvider
{
    /**
     * Fails with the exception of the database when it is unreachable: without the catalogue there is no dashboard.
     */
    public function statistics(): CatalogStatistics;
}
