<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

/**
 * The queues of the stock of the dashboard, from the module that owns the stock.
 */
interface StockQueues
{
    public function counts(): StockQueueCounts;
}
