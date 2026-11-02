<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Catalog;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\BackofficeProductPage;
use App\Catalog\Application\Query\InvalidProductCursor;
use App\Catalog\Application\Query\SearchBackofficeProducts;
use App\Pricing\Application\Port\CategoryNotFound;
use App\Pricing\Application\Port\DirectoryPage;
use App\Pricing\Application\Port\DirectoryProduct;
use App\Pricing\Application\Port\InvalidPriceListCursor;
use App\Pricing\Application\Port\ProductDirectory;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Tax\TaxClass;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Finds products through Catalog's search query on the query bus. Catalog's tax class codes become Pricing's TaxClass,
 * its not-found and cursor errors Pricing's own exceptions. Other errors (an out-of-range term or page size) pass on.
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
                $cause instanceof InvalidProductCursor => new InvalidPriceListCursor(),
                default => $cause,
            };
        }
        \assert($page instanceof BackofficeProductPage);

        return new DirectoryPage(
            array_map(
                static fn (BackofficeProduct $product): DirectoryProduct => new DirectoryProduct(ProductId::fromString($product->id), $product->name, TaxClass::fromString($product->taxClass)),
                $page->items,
            ),
            $page->next,
        );
    }
}
