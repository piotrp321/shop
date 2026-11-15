<?php

declare(strict_types=1);

namespace App\Inventory\UI\Console;

use App\Inventory\Application\StockRecords\StockRecordsSynced;
use App\Inventory\Application\StockRecords\StockRecordSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gives every variant of the catalog a stock record with its product: an empty one for a variant without a record,
 * the product for a record without it (data from before the column). Batches of products, each in its own
 * transaction; idempotent, so it can be stopped and run again. A step of app:backfill.
 * Exit codes: 0 done, 1 stopped by an error (the batches before it stay written).
 */
#[AsCommand(name: 'inventory:stock-records:sync', description: 'Gives every catalog variant a stock record with its product.')]
final readonly class SyncStockRecordsCommand
{
    public function __construct(private StockRecordSync $records)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $synced = $this->records->ofCatalog(static function (StockRecordsSynced $sofar) use ($io): void {
            if ($io->isVerbose()) {
                $io->writeln(\sprintf('products: %d', $sofar->products));
            }
        });

        $io->success($synced->summary());

        return Command::SUCCESS;
    }
}
