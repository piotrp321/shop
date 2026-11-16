<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Backfill;

use App\Ordering\Application\ReadModel\CustomerRebuild;
use App\Shared\Infrastructure\Backfill\BackfillStep;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A step of app:backfill: the customer part of ordering:read-models:rebuild, so the panel lists the customers of the
 * orders placed before the rollout. After the sales (a step of its own, so each read model is filled once).
 */
#[AsTaggedItem(priority: 90)]
final readonly class RebuildCustomersStep implements BackfillStep
{
    public function __construct(private CustomerRebuild $customers)
    {
    }

    public function name(): string
    {
        return 'ordering:read-models:rebuild:customers';
    }

    public function run(\Closure $report): void
    {
        $report($this->customers->run($report));
    }
}
