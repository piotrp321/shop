<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Backfill;

use App\Catalog\Application\Port\ProductTableStatistics;
use App\Shared\Infrastructure\Backfill\BackfillStep;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A step of app:backfill: ANALYZE of the product table, so the dashboard has the estimate of the number of products
 * (it never counts them) from the first rollout on, before the autovacuum has analysed a table that was never analysed.
 */
#[AsTaggedItem(priority: 200)]
final readonly class AnalyzeProductsStep implements BackfillStep
{
    public function __construct(private ProductTableStatistics $statistics)
    {
    }

    public function name(): string
    {
        return 'catalog:analyze-products';
    }

    public function run(\Closure $report): void
    {
        $this->statistics->refresh();
        $report('analysed catalog_product');
    }
}
