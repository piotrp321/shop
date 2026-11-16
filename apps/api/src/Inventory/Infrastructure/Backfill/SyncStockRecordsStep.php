<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Backfill;

use App\Inventory\Application\StockRecords\StockRecordSync;
use App\Shared\Infrastructure\Backfill\BackfillStep;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The first step of app:backfill: inventory:stock-records:sync, a stock record with its product for every variant.
 */
#[AsTaggedItem(priority: 300)]
final readonly class SyncStockRecordsStep implements BackfillStep
{
    public function __construct(private StockRecordSync $records)
    {
    }

    public function name(): string
    {
        return 'inventory:stock-records:sync';
    }

    public function run(\Closure $report): void
    {
        $report($this->records->ofCatalog()->summary());
    }
}
