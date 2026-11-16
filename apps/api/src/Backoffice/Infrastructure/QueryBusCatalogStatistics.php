<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure;

use App\Backoffice\Application\Port\CatalogCounts;
use App\Backoffice\Application\Port\CatalogStatistics;
use App\Catalog\Application\Query\CatalogStatisticsView;
use App\Catalog\Application\Query\GetCatalogStatistics;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks the Catalog module for its statistics through the query bus, the one dependency between the modules.
 */
final readonly class QueryBusCatalogStatistics implements CatalogStatistics
{
    use AsksQueryBus;

    public function __construct(#[Autowire(service: 'query.bus')] private MessageBusInterface $queryBus)
    {
    }

    public function counts(bool $withoutImages): CatalogCounts
    {
        /** @var CatalogStatisticsView $view */
        $view = self::ask($this->queryBus, new GetCatalogStatistics($withoutImages));

        return new CatalogCounts($view->productCount, $view->categoryCount, $view->productsWithoutImages, $view->indexedProducts);
    }
}
