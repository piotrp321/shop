<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Catalog;

use App\Catalog\Application\Query\GetVariantSummaries;
use App\Catalog\Application\Query\VariantSummary;
use App\Ordering\Application\Port\CatalogVariant;
use App\Ordering\Application\Port\OrderCatalog;
use App\Ordering\Domain\ProductId;
use App\Ordering\Domain\VariantId;
use App\Ordering\Infrastructure\BusFailure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The names of the variants through Catalog's GetVariantSummaries on the query bus (read from the Catalog tables,
 * never from the search index).
 */
final class QueryBusOrderCatalog implements OrderCatalog
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function variants(array $variantIds): array
    {
        try {
            $summaries = $this->handle(new GetVariantSummaries(array_map(static fn (VariantId $id): string => $id->toString(), $variantIds)));
        } catch (HandlerFailedException $failure) {
            throw BusFailure::cause($failure);
        }
        \assert(\is_array($summaries));

        $variants = [];
        foreach ($summaries as $summary) {
            \assert($summary instanceof VariantSummary);
            $variants[VariantId::fromString($summary->variantId)->toString()] = new CatalogVariant(ProductId::fromString($summary->productId), $summary->name, $summary->label);
        }

        return $variants;
    }
}
