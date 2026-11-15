<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Inventory\Application\Port\CategoryNotFound;
use App\Inventory\Application\Port\DescribedProduct;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\DirectoryVariant;
use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Application\Port\StockLevelProducts;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Shared\Domain\BoundedCount;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListBackofficeStockHandler
{
    public function __construct(
        private ProductDirectory $products,
        private StockItemRepository $stock,
        private StockLevelProducts $levels,
    ) {
    }

    /**
     * The page of products comes from Catalog (the search and the paging are its job), the variants of the whole page
     * from Catalog with one call and their stocks with one query. A variant without a stored stock is listed without
     * one; a stored stock, even an empty one at version 0, is listed as it is, so that a correction can be based on
     * its version. A product Catalog no longer has variants for (removed meanwhile) is listed without variants.
     *
     * With a level the page of products comes from the stock records instead (by product id, from the partial indexes
     * of the level), and Catalog only names the products and gives their variants; the variants at the level are marked.
     *
     * With a product id the page is that one product (a draft too) with all its variants, read by its id: the first
     * page, so with the bounded total of one, and an unknown product gives an empty page with a total of zero.
     *
     * @throws CategoryNotFound
     * @throws InvalidStockListCursor
     * @throws InvalidStockList
     */
    public function __invoke(ListBackofficeStock $query): StockList
    {
        if (null !== $query->productId) {
            return $this->ofProduct($query, ProductId::fromString($query->productId));
        }

        if (null !== $query->level) {
            return $this->atLevel($query, self::levelOf($query));
        }

        $page = $this->products->page($query->term, $query->categoryId, $query->after, $query->perPage, $query->sort);
        if ([] === $page->items) {
            return new StockList([], $page->next, $page->total);
        }

        $variants = $this->products->variantsOf(array_map(static fn (DirectoryProduct $product) => $product->id, $page->items));

        return new StockList(
            $this->listed(array_map(static fn (DirectoryProduct $product): DescribedProduct => new DescribedProduct($product, $variants[$product->id->toString()] ?? []), $page->items), null),
            $page->next,
            $page->total,
        );
    }

    /**
     * @throws InvalidStockList
     */
    private function ofProduct(ListBackofficeStock $query, ProductId $productId): StockList
    {
        if (null !== $query->term || null !== $query->categoryId || null !== $query->after || null !== $query->sort || null !== $query->level) {
            throw InvalidStockList::productWithFilters();
        }

        $described = $this->products->describe([$productId])[$productId->toString()] ?? null;
        if (null === $described) {
            return new StockList([], null, new BoundedCount(0, false));
        }

        return new StockList($this->listed([$described], null), null, new BoundedCount(1, false));
    }

    /**
     * One product more than the page is read: its presence tells there is a next page, and the cursor is the last product
     * of the page. The first page also carries the bounded total: the number of its rows when there is no next page
     * (nothing to count), otherwise the records are counted, never the table whole. A product Catalog does not have
     * (its records are not removed with it yet) is left out of the page.
     */
    private function atLevel(ListBackofficeStock $query, AvailabilityLevel $level): StockList
    {
        $after = null === $query->after ? null : StockLevelCursor::decode($query->after, $level)->productId;
        $ids = $this->levels->page($level, $after, $query->perPage + 1);
        $next = null;
        if (\count($ids) > $query->perPage) {
            $ids = \array_slice($ids, 0, $query->perPage);
            $next = StockLevelCursor::after($level, $ids[$query->perPage - 1])->encode();
            $total = null === $after ? $this->levels->count($level) : null;
        } else {
            $total = null === $after ? new BoundedCount(\count($ids), false) : null;
        }
        if ([] === $ids) {
            return new StockList([], $next, $total);
        }

        $described = $this->products->describe($ids);
        $products = [];
        foreach ($ids as $id) {
            if (isset($described[$id->toString()])) {
                $products[] = $described[$id->toString()];
            }
        }

        return new StockList($this->listed($products, $level), $next, $total);
    }

    /**
     * @throws InvalidStockList
     */
    private static function levelOf(ListBackofficeStock $query): AvailabilityLevel
    {
        $level = null === $query->level ? null : AvailabilityLevel::tryFrom($query->level);
        if (null === $level || AvailabilityLevel::InStock === $level) {
            throw InvalidStockList::unknownLevel();
        }
        if (null !== $query->term || null !== $query->categoryId || null !== $query->sort) {
            throw InvalidStockList::levelWithFilters();
        }

        return $level;
    }

    /**
     * The products with the stocks of all their variants, read with one query.
     *
     * @param list<DescribedProduct> $products
     *
     * @return list<StockListItem>
     */
    private function listed(array $products, ?AvailabilityLevel $level): array
    {
        $variantIds = [];
        foreach ($products as $described) {
            foreach ($described->variants as $variant) {
                $variantIds[] = $variant->id;
            }
        }

        $stored = [];
        if ([] !== $variantIds) {
            foreach ($this->stock->findMany($variantIds) as $item) {
                $stored[$item->id()->toString()] = $item;
            }
        }

        return array_map(static fn (DescribedProduct $described): StockListItem => self::item($described, $stored, $level), $products);
    }

    /**
     * @param array<string, StockItem> $stored variant id => stock
     */
    private static function item(DescribedProduct $described, array $stored, ?AvailabilityLevel $level): StockListItem
    {
        return new StockListItem(
            $described->product->id->toString(),
            $described->product->name,
            array_map(
                static function (DirectoryVariant $variant) use ($stored, $level): StockListVariant {
                    $item = $stored[$variant->id->toString()] ?? null;
                    $listed = null === $item ? null : ListedStock::of($item);

                    return new StockListVariant($variant->id->toString(), $variant->label, $listed, null !== $level && $listed?->level === $level->value);
                },
                $described->variants,
            ),
        );
    }
}
