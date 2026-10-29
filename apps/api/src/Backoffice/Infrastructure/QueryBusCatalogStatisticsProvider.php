<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure;

use App\Backoffice\Application\Port\CatalogStatistics;
use App\Backoffice\Application\Port\CatalogStatisticsProvider;
use App\Catalog\Application\Query\CatalogStatisticsView;
use App\Catalog\Application\Query\GetCatalogStatistics;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks the Catalog module for its statistics through the query bus, the one dependency between the modules.
 */
final class QueryBusCatalogStatisticsProvider implements CatalogStatisticsProvider
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function statistics(): CatalogStatistics
    {
        try {
            /** @var CatalogStatisticsView $view */
            $view = $this->handle(new GetCatalogStatistics());
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw; the caller deals with the cause (e.g. a lost connection).
            $wrapped = $failure->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
        }

        return new CatalogStatistics($view->productCount, $view->categoryCount, $view->productsWithoutImages, $view->indexedProducts);
    }
}
