<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure;

use App\Backoffice\Application\Port\OrderQueueCounts;
use App\Backoffice\Application\Port\OrderQueues;
use App\Ordering\Application\Query\GetOrderQueues;
use App\Ordering\Application\Query\OrderQueues as OrderQueuesView;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks the Ordering module for the queues of the orders through the query bus.
 */
final readonly class QueryBusOrderQueues implements OrderQueues
{
    use AsksQueryBus;

    public function __construct(#[Autowire(service: 'query.bus')] private MessageBusInterface $queryBus)
    {
    }

    public function counts(bool $paidToShip, bool $awaitingPayment, bool $refunds): OrderQueueCounts
    {
        /** @var OrderQueuesView $view */
        $view = self::ask($this->queryBus, new GetOrderQueues($paidToShip, $awaitingPayment, $refunds, $refunds));

        return new OrderQueueCounts($view->paidToShip, $view->awaitingPayment, $view->pendingRefunds, $view->overdueRefunds);
    }
}
