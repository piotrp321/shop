<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Catalog;

use App\Catalog\Application\Query\GetVariant;
use App\Catalog\Application\Query\ListVariantIdentities;
use App\Catalog\Application\Query\VariantIdentity;
use App\Catalog\Application\Query\VariantInfo;
use App\Pricing\Application\Port\CatalogVariant;
use App\Pricing\Application\Port\VariantsForPricing;
use App\Pricing\Application\Port\VariantTaxClasses;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Tax\TaxClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks Catalog about variants through the query bus, the one dependency of Pricing on Catalog. Catalog's tax class
 * codes (of the product of a variant) become Pricing's TaxClass here.
 */
final class QueryBusCatalogVariants implements VariantTaxClasses, VariantsForPricing
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function taxClassOf(VariantId $variantId): ?TaxClass
    {
        $variant = $this->ask(new GetVariant($variantId->toString()));
        \assert(null === $variant || $variant instanceof VariantInfo);

        return null === $variant ? null : TaxClass::fromString($variant->taxClass);
    }

    public function page(?VariantId $after, int $limit): array
    {
        $batch = $this->ask(new ListVariantIdentities($after?->toString(), $limit));
        \assert(\is_array($batch));

        return array_values(array_map(static function (mixed $variant): CatalogVariant {
            \assert($variant instanceof VariantIdentity);

            return new CatalogVariant(VariantId::fromString($variant->variantId), $variant->key, TaxClass::fromString($variant->taxClass));
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
