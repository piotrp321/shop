<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Inventory;

use App\Cart\Application\Port\ProductAvailability;
use App\Cart\Application\Port\StockAvailability;
use App\Cart\Domain\ProductId;
use App\Cart\Infrastructure\QueryBusFailure;
use App\Inventory\Application\Query\GetStockLevels;
use App\Inventory\Application\Query\StockLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The available units through Inventory's GetStockLevels on the query bus (read in the process, never cached).
 */
final class QueryBusStockAvailability implements StockAvailability
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function of(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        try {
            $levels = $this->handle(new GetStockLevels(array_map(static fn (ProductId $id): string => $id->toString(), $ids)));
        } catch (HandlerFailedException $failure) {
            throw QueryBusFailure::cause($failure);
        }
        \assert(\is_array($levels));

        return array_values(array_map(static function (mixed $level): ProductAvailability {
            \assert($level instanceof StockLevel);

            return new ProductAvailability(ProductId::fromString($level->productId), max(0, $level->available));
        }, $levels));
    }
}
