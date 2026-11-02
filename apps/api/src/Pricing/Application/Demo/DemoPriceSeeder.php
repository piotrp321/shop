<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

use App\Pricing\Application\Command\SetProductPrice;
use App\Pricing\Application\Port\CatalogProduct;
use App\Pricing\Application\Port\ProductsForPricing;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sets the demo price of every Catalog product through SetProductPrice (the `command.bus`, injected by the
 * parameter name), with the source `seed`. Products are read in batches, so the catalog is never held in memory.
 */
final readonly class DemoPriceSeeder
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private ProductsForPricing $products,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @return list<string> slugs of the products no pattern matches, in id order
     */
    public function unpricedSlugs(DemoPriceList $prices): array
    {
        $unpriced = [];
        foreach ($this->allProducts() as $product) {
            if (null === $prices->grossFor($product->slug)) {
                $unpriced[] = $product->slug;
            }
        }

        return $unpriced;
    }

    /**
     * Each price is set in its own transaction. The products must have no price yet (see PricingReset).
     *
     * @return int the number of prices set
     *
     * @throws \RuntimeException at the first product no pattern matches
     */
    public function seed(DemoPriceList $prices): int
    {
        $count = 0;
        foreach ($this->allProducts() as $product) {
            $gross = $prices->grossFor($product->slug)
                ?? throw new \RuntimeException(\sprintf('No demo price pattern matches the product "%s".', $product->slug));
            $this->commandBus->dispatch(SetProductPrice::bySeed($product->id->toString(), $gross));
            ++$count;
        }

        return $count;
    }

    /**
     * @return iterable<CatalogProduct>
     */
    private function allProducts(): iterable
    {
        $after = null;
        do {
            $batch = $this->products->page($after, self::BATCH_SIZE);
            yield from $batch;
            $after = [] === $batch ? null : $batch[\count($batch) - 1]->id;
        } while (self::BATCH_SIZE === \count($batch));
    }
}
