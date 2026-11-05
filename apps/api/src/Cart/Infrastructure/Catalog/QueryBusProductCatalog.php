<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Catalog;

use App\Cart\Application\Port\ProductCatalog;
use App\Cart\Application\Port\ProductImage;
use App\Cart\Application\Port\ProductVariants;
use App\Cart\Application\Port\VariantLabel;
use App\Cart\Application\Port\VariantSummary;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\VariantId;
use App\Cart\Infrastructure\QueryBusFailure;
use App\Catalog\Application\Query\GetVariantSummaries;
use App\Catalog\Application\Query\ListProductVariants;
use App\Catalog\Application\Query\ProductVariantList;
use App\Catalog\Application\Query\VariantOption;
use App\Catalog\Application\Query\VariantSummary as CatalogVariantSummary;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The variants of the lines of a cart (names, labels and images: Catalog's GetVariantSummaries) and the variants of
 * the products of the list (Catalog's ListProductVariants), on the query bus.
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

        $summaries = $this->ask(new GetVariantSummaries(array_map(static fn (VariantId $id): string => $id->toString(), $ids)));

        return array_values(array_map(static function (mixed $summary): VariantSummary {
            \assert($summary instanceof CatalogVariantSummary);
            $image = $summary->image;

            return new VariantSummary(
                VariantId::fromString($summary->variantId),
                ProductId::fromString($summary->productId),
                $summary->name,
                $summary->label,
                null === $image ? null : new ProductImage($image->alt, $image->size, $image->aspectRatio, $image->variants),
            );
        }, $summaries));
    }

    public function variantsOf(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $lists = $this->ask(new ListProductVariants(array_map(static fn (ProductId $id): string => $id->toString(), $ids)));

        return array_values(array_map(static function (mixed $list): ProductVariants {
            \assert($list instanceof ProductVariantList);

            return new ProductVariants(
                ProductId::fromString($list->productId),
                $list->variantAxes,
                array_map(static fn (VariantOption $variant): VariantLabel => new VariantLabel(VariantId::fromString($variant->variantId), $variant->label), $list->variants),
            );
        }, $lists));
    }

    /**
     * @return array<mixed>
     */
    private function ask(object $query): array
    {
        try {
            $result = $this->handle($query);
        } catch (HandlerFailedException $failure) {
            throw QueryBusFailure::cause($failure);
        }
        \assert(\is_array($result));

        return $result;
    }
}
