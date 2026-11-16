<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure;

use App\Backoffice\Application\Port\PeriodFigures;
use App\Backoffice\Application\Port\SalesFigures;
use App\Backoffice\Application\Port\SalesSummary;
use App\Ordering\Application\Query\GetSalesSummary;
use App\Ordering\Application\Query\PeriodSales;
use App\Ordering\Application\Query\SalesSummary as SalesSummaryView;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks the Ordering module for the sales of the dashboard through the query bus.
 */
final readonly class QueryBusSalesSummary implements SalesSummary
{
    use AsksQueryBus;

    public function __construct(#[Autowire(service: 'query.bus')] private MessageBusInterface $queryBus)
    {
    }

    public function summary(): SalesFigures
    {
        /** @var SalesSummaryView $view */
        $view = self::ask($this->queryBus, new GetSalesSummary());

        return new SalesFigures($view->dataUntil, $view->currency, self::period($view->today), self::period($view->last7Days), self::period($view->last30Days));
    }

    private static function period(PeriodSales $sales): PeriodFigures
    {
        return new PeriodFigures($sales->revenue, $sales->orderCount, $sales->averageOrderValue, $sales->changeBasisPoints);
    }
}
