<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Catalog;

use App\Catalog\Application\Port\VariantStockFacts;
use App\Inventory\Application\Query\GetStockLevels;
use App\Inventory\Application\Query\StockLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tells Catalog which variants have stock (physical or reserved), so that it refuses to remove them from their
 * product (decision A6 of CATALOG-ADMIN). One GetStockLevels on the query bus, read without a lock; a variant without
 * a stock record has none. Catalog depends on Inventory in no layer: the port is Catalog's, the adapter Inventory's.
 */
final class QueryBusVariantStockFacts implements VariantStockFacts
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function inUse(array $variantIds): array
    {
        if ([] === $variantIds) {
            return [];
        }

        try {
            $levels = $this->handle(new GetStockLevels($variantIds));
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw.
            $wrapped = $failure->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
        }
        \assert(\is_array($levels));

        $inUse = [];
        foreach ($levels as $level) {
            \assert($level instanceof StockLevel);
            if ($level->physical > 0 || $level->reserved > 0) {
                $inUse[] = $level->variantId;
            }
        }

        return $inUse;
    }
}
