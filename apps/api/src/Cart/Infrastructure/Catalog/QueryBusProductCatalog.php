<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Catalog;

use App\Cart\Application\Port\ProductCatalog;
use App\Cart\Application\Port\ProductImage;
use App\Cart\Application\Port\ProductSummary;
use App\Cart\Domain\ProductId;
use App\Cart\Infrastructure\QueryBusFailure;
use App\Catalog\Application\Query\GetProductSummaries;
use App\Catalog\Application\Query\ProductSummary as CatalogProductSummary;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Names and images of the products of a cart through Catalog's GetProductSummaries on the query bus.
 */
final class QueryBusProductCatalog implements ProductCatalog
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function summaries(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        try {
            $summaries = $this->handle(new GetProductSummaries(array_map(static fn (ProductId $id): string => $id->toString(), $ids)));
        } catch (HandlerFailedException $failure) {
            throw QueryBusFailure::cause($failure);
        }
        \assert(\is_array($summaries));

        return array_values(array_map(static function (mixed $summary): ProductSummary {
            \assert($summary instanceof CatalogProductSummary);
            $image = $summary->image;

            return new ProductSummary(
                ProductId::fromString($summary->id),
                $summary->name,
                null === $image ? null : new ProductImage($image->alt, $image->size, $image->aspectRatio, $image->variants),
            );
        }, $summaries));
    }
}
