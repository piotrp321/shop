<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Catalog;

use App\Catalog\Application\Query\GetVariant;
use App\Catalog\Application\Query\ListVariantIdentities;
use App\Catalog\Application\Query\VariantIdentity;
use App\Catalog\Application\Query\VariantInfo;
use App\Inventory\Application\Port\CatalogVariant;
use App\Inventory\Application\Port\VariantCatalog;
use App\Inventory\Application\Port\VariantsForInventory;
use App\Inventory\Domain\Stock\VariantId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks Catalog about variants through its queries on the query bus, the one dependency of Inventory on Catalog.
 */
final class QueryBusCatalogVariants implements VariantCatalog, VariantsForInventory
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function exists(VariantId $id): bool
    {
        $variant = $this->ask(new GetVariant($id->toString()));
        \assert(null === $variant || $variant instanceof VariantInfo);

        return null !== $variant;
    }

    public function page(?VariantId $after, int $limit): array
    {
        $batch = $this->ask(new ListVariantIdentities($after?->toString(), $limit));
        \assert(\is_array($batch));

        return array_values(array_map(static function (mixed $variant): CatalogVariant {
            \assert($variant instanceof VariantIdentity);

            return new CatalogVariant(VariantId::fromString($variant->variantId), $variant->key);
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
