<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Inventory;

use App\Cart\Application\Port\ProductAvailability;
use App\Cart\Application\Port\StockAvailability;
use App\Cart\Domain\ProductId;
use App\Cart\Infrastructure\QueryBusFailure;
use App\Inventory\Application\Query\GetProductStockLevels;
use App\Inventory\Application\Query\ProductStockLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The available units through Inventory on the query bus (read in the process, never cached).
 *
 * Transitional until the cart lines are kept per variant (VARIANTS-4): Inventory keeps stock per variant, and
 * GetProductStockLevels gives a product with exactly one variant (in Catalog's ListProductVariants) the available
 * units of that variant and a product with more variants none, the rule of GET /api/v1/availability.
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
            $levels = $this->handle(new GetProductStockLevels(array_map(static fn (ProductId $id): string => $id->toString(), $ids)));
        } catch (HandlerFailedException $failure) {
            throw QueryBusFailure::cause($failure);
        }
        \assert(\is_array($levels));

        return array_values(array_map(static function (mixed $level): ProductAvailability {
            \assert($level instanceof ProductStockLevel);

            return new ProductAvailability(ProductId::fromString($level->productId), max(0, $level->available));
        }, $levels));
    }
}
