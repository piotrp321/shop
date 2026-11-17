<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure;

use App\Backoffice\Application\Port\StockQueueCounts;
use App\Backoffice\Application\Port\StockQueues;
use App\Inventory\Application\Query\GetStockQueues;
use App\Inventory\Application\Query\StockQueues as StockQueuesView;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks the Inventory module for the queues of the stock through the query bus.
 */
final readonly class QueryBusStockQueues implements StockQueues
{
    use AsksQueryBus;

    public function __construct(#[Autowire(service: 'query.bus')] private MessageBusInterface $queryBus)
    {
    }

    public function counts(): StockQueueCounts
    {
        /** @var StockQueuesView $view */
        $view = self::ask($this->queryBus, new GetStockQueues());

        return new StockQueueCounts($view->outOfStockVariants, $view->lowStockVariants);
    }
}
