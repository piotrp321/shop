<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Inventory\Application\Port\StockLevelProducts;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Counts the stock records at the two levels of the stock list on the partial indexes of those levels: the same
 * conditions as the list the queue links to, so the queue and the list agree.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetStockQueuesHandler
{
    /** The dashboard counts up to this many; more is shown as "1000+". */
    public const int LIMIT = 1000;

    public function __construct(private StockLevelProducts $levels)
    {
    }

    public function __invoke(GetStockQueues $query): StockQueues
    {
        return new StockQueues(
            $this->levels->countVariants(AvailabilityLevel::OutOfStock, self::LIMIT),
            $this->levels->countVariants(AvailabilityLevel::LowStock, self::LIMIT),
        );
    }
}
