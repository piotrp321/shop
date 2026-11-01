<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\CategoryNotFound;
use App\Pricing\Application\Port\DirectoryProduct;
use App\Pricing\Application\Port\InvalidPriceListCursor;
use App\Pricing\Application\Port\ProductDirectory;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Price\ProductPriceRepository;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRates;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListBackofficePricesHandler
{
    public function __construct(
        private ProductDirectory $products,
        private ProductPriceRepository $prices,
        private VatRates $rates,
    ) {
    }

    /**
     * The page of products comes from Catalog (the search and the paging are its job); the prices of the whole page
     * are read with one query. A product without a price is listed without one.
     *
     * @throws CategoryNotFound
     * @throws InvalidPriceListCursor
     * @throws TaxClassWithoutRate    when Catalog gives a class that has no rate (the contract test prevents it)
     */
    public function __invoke(ListBackofficePrices $query): PriceList
    {
        $page = $this->products->page($query->term, $query->categoryId, $query->after, $query->perPage);
        if ([] === $page->items) {
            return new PriceList([], $page->next);
        }

        $stored = [];
        foreach ($this->prices->findMany(array_map(static fn (DirectoryProduct $product) => $product->id, $page->items)) as $price) {
            $stored[$price->id()->toString()] = $price;
        }

        return new PriceList(
            array_map(fn (DirectoryProduct $product): PriceListItem => $this->item($product, $stored[$product->id->toString()] ?? null), $page->items),
            $page->next,
        );
    }

    private function item(DirectoryProduct $product, ?ProductPrice $price): PriceListItem
    {
        return new PriceListItem(
            $product->id->toString(),
            $product->name,
            $product->taxClass->toString(),
            $this->rates->rateFor($product->taxClass)->basisPoints(),
            null === $price ? null : $this->listed($price),
        );
    }

    private function listed(ProductPrice $price): ListedPrice
    {
        $breakdown = $price->breakdown();

        return new ListedPrice(
            $breakdown->gross()->amount(),
            $breakdown->net()->amount(),
            $breakdown->vat()->amount(),
            $breakdown->rate()->basisPoints(),
            $breakdown->currency()->value,
            $price->version(),
            $price->updatedAt(),
            !$price->isCurrent($this->rates),
        );
    }
}
