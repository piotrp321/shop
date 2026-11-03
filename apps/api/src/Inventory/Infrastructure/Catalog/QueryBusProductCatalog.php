<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Catalog;

use App\Catalog\Application\Query\GetProductExists;
use App\Inventory\Application\Port\ProductCatalog;
use App\Inventory\Domain\Stock\ProductId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks Catalog about products through its query on the query bus, the one dependency of Inventory on Catalog.
 */
final class QueryBusProductCatalog implements ProductCatalog
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function exists(ProductId $id): bool
    {
        try {
            $exists = $this->handle(new GetProductExists($id->toString()));
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw; the caller deals with the cause (e.g. a lost connection).
            $wrapped = $failure->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
        }
        \assert(\is_bool($exists));

        return $exists;
    }
}
