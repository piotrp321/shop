<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Backfill;

use App\Ordering\Application\ReadModel\SalesRebuild;
use App\Shared\Infrastructure\Backfill\BackfillStep;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A step of app:backfill: the sales part of ordering:read-models:rebuild, so the dashboard has the sales of the orders
 * paid before the rollout. Each read model of ordering:read-models:rebuild has a step of its own, so one added later
 * runs on the next deployment even though this one completed.
 */
#[AsTaggedItem(priority: 100)]
final readonly class RebuildSalesStep implements BackfillStep
{
    public function __construct(private SalesRebuild $sales)
    {
    }

    public function name(): string
    {
        return 'ordering:read-models:rebuild:sales';
    }

    public function run(\Closure $report): void
    {
        $report($this->sales->run($report));
    }
}
