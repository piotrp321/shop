<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Application;

use App\Backoffice\Application\Port\CatalogCounts;
use App\Backoffice\Application\Port\CatalogStatistics;

final class RecordingCatalogStatistics implements CatalogStatistics
{
    /** @var list<bool> whether every call asked for the products without images */
    public array $asked = [];

    public function __construct(private readonly CatalogCounts $counts)
    {
    }

    public function counts(bool $withoutImages): CatalogCounts
    {
        $this->asked[] = $withoutImages;

        return new CatalogCounts(
            $this->counts->productCount,
            $this->counts->categoryCount,
            $withoutImages ? $this->counts->productsWithoutImages : null,
            $this->counts->indexedProducts,
        );
    }
}
