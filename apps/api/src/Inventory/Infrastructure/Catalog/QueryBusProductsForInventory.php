<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Catalog;

use App\Catalog\Application\Query\ListProductIdentities;
use App\Catalog\Application\Query\ProductIdentity;
use App\Inventory\Application\Port\CatalogProduct;
use App\Inventory\Application\Port\ProductsForInventory;
use App\Inventory\Domain\Stock\ProductId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pages through the products of Catalog with its query on the query bus, the one dependency of Inventory on Catalog.
 */
final class QueryBusProductsForInventory implements ProductsForInventory
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function page(?ProductId $after, int $limit): array
    {
        try {
            $batch = $this->handle(new ListProductIdentities($after?->toString(), $limit));
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw; the caller deals with the cause (e.g. a lost connection).
            $wrapped = $failure->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
        }
        \assert(\is_array($batch));

        return array_values(array_map(static function (mixed $identity): CatalogProduct {
            \assert($identity instanceof ProductIdentity);

            return new CatalogProduct(ProductId::fromString($identity->id), $identity->slug);
        }, $batch));
    }
}
