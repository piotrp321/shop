<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Catalog;

use App\Catalog\Application\Query\GetProductTaxClass;
use App\Catalog\Application\Query\ListProductsForPricing;
use App\Catalog\Application\Query\ProductForPricing;
use App\Pricing\Application\Port\CatalogProduct;
use App\Pricing\Application\Port\ProductsForPricing;
use App\Pricing\Application\Port\ProductTaxClasses;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Tax\TaxClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks Catalog about products through the query bus, the one dependency of Pricing on Catalog. Catalog's tax class
 * codes become Pricing's TaxClass here.
 */
final class QueryBusCatalogProducts implements ProductTaxClasses, ProductsForPricing
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function taxClassOf(ProductId $productId): ?TaxClass
    {
        $code = $this->ask(new GetProductTaxClass($productId->toString()));
        \assert(null === $code || \is_string($code));

        return null === $code ? null : TaxClass::fromString($code);
    }

    public function page(?ProductId $after, int $limit): array
    {
        $batch = $this->ask(new ListProductsForPricing($after?->toString(), $limit));
        \assert(\is_array($batch));

        return array_values(array_map(static function (mixed $product): CatalogProduct {
            \assert($product instanceof ProductForPricing);

            return new CatalogProduct(ProductId::fromString($product->id), $product->slug, TaxClass::fromString($product->taxClass));
        }, $batch));
    }

    private function ask(object $query): mixed
    {
        try {
            return $this->handle($query);
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw; the caller deals with the cause (e.g. a lost connection).
            $wrapped = $failure->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
        }
    }
}
