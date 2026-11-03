<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Catalog;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\BackofficeProductPage;
use App\Catalog\Application\Query\InvalidProductCursor;
use App\Catalog\Application\Query\SearchBackofficeProducts;
use App\Inventory\Application\Port\CategoryNotFound;
use App\Inventory\Application\Port\DirectoryPage;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Domain\Stock\ProductId;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Finds products through Catalog's search query on the query bus. Its not-found and cursor errors become Inventory's
 * own exceptions. Other errors (an out-of-range term or page size) pass on.
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
}
