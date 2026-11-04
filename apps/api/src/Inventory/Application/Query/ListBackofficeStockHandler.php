<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Inventory\Application\Port\CategoryNotFound;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\DirectoryVariant;
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
     * The page of products comes from Catalog (the search and the paging are its job), the variants of the whole page
     * from Catalog with one call and their stocks with one query. A variant without a stored stock is listed without
     * one; a stored stock, even an empty one at version 0, is listed as it is, so that a correction can be based on
     * its version. A product Catalog no longer has variants for (removed meanwhile) is listed without variants.
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

        $variants = $this->products->variantsOf(array_map(static fn (DirectoryProduct $product) => $product->id, $page->items));
        $variantIds = [];
        foreach ($variants as $ofProduct) {
            foreach ($ofProduct as $variant) {
                $variantIds[] = $variant->id;
            }
        }

        $stored = [];
        if ([] !== $variantIds) {
            foreach ($this->stock->findMany($variantIds) as $item) {
                $stored[$item->id()->toString()] = $item;
            }
        }

        return new StockList(
            array_map(static fn (DirectoryProduct $product): StockListItem => self::item($product, $variants[$product->id->toString()] ?? [], $stored), $page->items),
            $page->next,
        );
    }

    /**
     * @param list<DirectoryVariant>   $variants
     * @param array<string, StockItem> $stored   variant id => stock
     */
    private static function item(DirectoryProduct $product, array $variants, array $stored): StockListItem
    {
        return new StockListItem(
            $product->id->toString(),
            $product->name,
            array_map(
                static function (DirectoryVariant $variant) use ($stored): StockListVariant {
                    $item = $stored[$variant->id->toString()] ?? null;

                    return new StockListVariant($variant->id->toString(), $variant->label, null === $item ? null : ListedStock::of($item));
                },
                $variants,
            ),
        );
    }
}
