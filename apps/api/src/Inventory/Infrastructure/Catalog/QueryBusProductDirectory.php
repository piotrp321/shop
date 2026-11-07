<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Catalog;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\BackofficeProductPage;
use App\Catalog\Application\Query\InvalidProductCursor;
use App\Catalog\Application\Query\ListProductVariants;
use App\Catalog\Application\Query\ProductVariantList;
use App\Catalog\Application\Query\SearchBackofficeProducts;
use App\Catalog\Application\Query\VariantOption;
use App\Inventory\Application\Port\CategoryNotFound;
use App\Inventory\Application\Port\DirectoryPage;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\DirectoryVariant;
use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\VariantId;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Finds products through Catalog's search query and their variants through ListProductVariants (drafts included), on
 * the query bus. Catalog's not-found and cursor errors become Inventory's own exceptions. Other errors (an out-of-range
 * term or page size, too many products) pass on.
 */
final class QueryBusProductDirectory implements ProductDirectory
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function page(?string $term, ?string $categoryId, ?string $after, int $perPage): DirectoryPage
    {
        try {
            $page = $this->handle(new SearchBackofficeProducts($term, $categoryId, $after, $perPage));
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw.
            $wrapped = $failure->getWrappedExceptions();
            $cause = 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;

            throw match (true) {
                $cause instanceof NotFound => new CategoryNotFound(),
                $cause instanceof InvalidProductCursor => new InvalidStockListCursor(),
                default => $cause,
            };
        }
        \assert($page instanceof BackofficeProductPage);

        return new DirectoryPage(
            array_map(
                static fn (BackofficeProduct $product): DirectoryProduct => new DirectoryProduct(ProductId::fromString($product->id), $product->name),
                $page->items,
            ),
            $page->next,
        );
    }

    public function variantsOf(array $productIds): array
    {
        try {
            // The panel lists drafts too: their prices and stock are set before the publication.
            $lists = $this->handle(new ListProductVariants(array_map(static fn (ProductId $id): string => $id->toString(), $productIds), includeDrafts: true));
        } catch (HandlerFailedException $failure) {
            $wrapped = $failure->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
        }
        \assert(\is_array($lists));

        $variants = [];
        foreach ($lists as $list) {
            \assert($list instanceof ProductVariantList);
            $variants[$list->productId] = array_map(
                static fn (VariantOption $variant): DirectoryVariant => new DirectoryVariant(VariantId::fromString($variant->variantId), $variant->label),
                $list->variants,
            );
        }

        return $variants;
    }
}
