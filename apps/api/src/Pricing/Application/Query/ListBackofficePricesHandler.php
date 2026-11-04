<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\CategoryNotFound;
use App\Pricing\Application\Port\DirectoryProduct;
use App\Pricing\Application\Port\DirectoryVariant;
use App\Pricing\Application\Port\InvalidPriceListCursor;
use App\Pricing\Application\Port\ProductDirectory;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRates;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListBackofficePricesHandler
{
    public function __construct(
        private ProductDirectory $products,
        private VariantPriceRepository $prices,
        private VatRates $rates,
    ) {
    }

    /**
     * The page of products comes from Catalog (the search and the paging are its job), the variants of the whole page
     * from Catalog with one call and their prices with one query. A variant without a price is listed without one;
     * a product Catalog no longer has variants for (removed meanwhile) is listed without variants.
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

        $variants = $this->products->variantsOf(array_map(static fn (DirectoryProduct $product) => $product->id, $page->items));
        $variantIds = [];
        foreach ($variants as $ofProduct) {
            foreach ($ofProduct as $variant) {
                $variantIds[] = $variant->id;
            }
        }

        $stored = [];
        if ([] !== $variantIds) {
            foreach ($this->prices->findMany($variantIds) as $price) {
                $stored[$price->id()->toString()] = $price;
            }
        }

        return new PriceList(
            array_map(fn (DirectoryProduct $product): PriceListItem => $this->item($product, $variants[$product->id->toString()] ?? [], $stored), $page->items),
            $page->next,
        );
    }

    /**
     * @param list<DirectoryVariant>      $variants
     * @param array<string, VariantPrice> $stored   variant id => price
     */
    private function item(DirectoryProduct $product, array $variants, array $stored): PriceListItem
    {
        return new PriceListItem(
            $product->id->toString(),
            $product->name,
            $product->taxClass->toString(),
            $this->rates->rateFor($product->taxClass)->basisPoints(),
            array_map(
                function (DirectoryVariant $variant) use ($stored): PriceListVariant {
                    $price = $stored[$variant->id->toString()] ?? null;

                    return new PriceListVariant($variant->id->toString(), $variant->label, null === $price ? null : ListedPrice::of($price, $this->rates));
                },
                $variants,
            ),
        );
    }
}
