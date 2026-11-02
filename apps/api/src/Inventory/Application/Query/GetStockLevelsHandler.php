<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\StockItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetStockLevelsHandler
{
    public function __construct(private StockItemRepository $stock)
    {
    }

    /**
     * One read for all the products. Every requested product has a level, in the order of the request; a repeated
     * id answers once.
     *
     * @return list<StockLevel>
     *
     * @throws InvalidStockLevelsQuery
     */
    public function __invoke(GetStockLevels $query): array
    {
        $count = \count($query->productIds);
        if ($count < 1 || $count > GetStockLevels::MAX_PRODUCTS) {
            throw InvalidStockLevelsQuery::productCountOutOfRange();
        }

        $ids = [];
        foreach ($query->productIds as $raw) {
            try {
                $id = ProductId::fromString($raw);
            } catch (\InvalidArgumentException) {
                throw InvalidStockLevelsQuery::notAProductId();
            }
            $ids[$id->toString()] = $id;
        }

        $stored = [];
        foreach ($this->stock->findMany(array_values($ids)) as $item) {
            $stored[$item->id()->toString()] = $item;
        }

        $levels = [];
        foreach (array_keys($ids) as $key) {
            $item = $stored[$key] ?? null;
            $levels[] = null === $item
                ? new StockLevel($key, 0, 0, 0, AvailabilityLevel::of(0)->value, null)
                : new StockLevel($key, $item->physical(), $item->reserved(), $item->available(), AvailabilityLevel::of($item->available())->value, $item->version());
        }

        return $levels;
    }
}
