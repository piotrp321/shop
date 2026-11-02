<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Inventory\Application\Port\CategoryNotFound;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListBackofficeStockHandler
{
    public function __construct(
        private ProductDirectory $products,
        private StockItemRepository $stock,
    ) {
    }

    /**
     * The page of products comes from Catalog (the search and the paging are its job); the stocks of the whole page
     * are read with one query. A product without a stored stock, or with an empty one no change was ever saved for
     * (version 0, the same as no stock for a correction), is listed without a stock.
     *
     * @throws CategoryNotFound
     * @throws InvalidStockListCursor
     */
    public function __invoke(ListBackofficeStock $query): StockList
    {
        $page = $this->products->page($query->term, $query->categoryId, $query->after, $query->perPage);
        if ([] === $page->items) {
            return new StockList([], $page->next);
        }

        $stored = [];
        foreach ($this->stock->findMany(array_map(static fn (DirectoryProduct $product) => $product->id, $page->items)) as $item) {
            $stored[$item->id()->toString()] = $item;
        }

        return new StockList(
            array_map(fn (DirectoryProduct $product): StockListItem => $this->item($product, $stored[$product->id->toString()] ?? null), $page->items),
            $page->next,
        );
    }

    private function item(DirectoryProduct $product, ?StockItem $item): StockListItem
    {
        return new StockListItem(
            $product->id->toString(),
            $product->name,
            null === $item || 0 === $item->version() ? null : ListedStock::of($item),
        );
    }
}
