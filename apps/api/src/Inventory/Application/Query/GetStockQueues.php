<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * The queues of the stock for the dashboard: the variants out of stock and with a low stock. The caller decides, by
 * the voter of this module, whether the staff member may see them.
 */
final readonly class GetStockQueues
{
}
