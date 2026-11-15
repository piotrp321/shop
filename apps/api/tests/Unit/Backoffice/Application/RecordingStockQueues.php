<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Application;

use App\Backoffice\Application\Port\StockQueueCounts;
use App\Backoffice\Application\Port\StockQueues;

final class RecordingStockQueues implements StockQueues
{
    public int $calls = 0;

    public function __construct(private readonly StockQueueCounts $counts)
    {
    }

    public function counts(): StockQueueCounts
    {
        ++$this->calls;

        return $this->counts;
    }
}
