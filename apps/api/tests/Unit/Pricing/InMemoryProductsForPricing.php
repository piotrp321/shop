<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\CatalogProduct;
use App\Pricing\Application\Port\ProductsForPricing;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Tax\TaxClass;

final class InMemoryProductsForPricing implements ProductsForPricing
{
    /** @var list<int> the limit of every page asked for */
    public array $pageLimits = [];
    /** @var list<CatalogProduct> ordered by id */
    private array $products = [];

    /**
     * @param array<string, string> $slugs product id => slug; every product is `standard`
     */
    public function __construct(array $slugs = [])
    {
        ksort($slugs);
        foreach ($slugs as $id => $slug) {
            $this->products[] = new CatalogProduct(ProductId::fromString($id), $slug, TaxClass::fromString('standard'));
        }
    }

    public function page(?ProductId $after, int $limit): array
    {
        $this->pageLimits[] = $limit;
        $rest = null === $after
            ? $this->products
            : array_values(array_filter($this->products, static fn (CatalogProduct $product): bool => $product->id->toString() > $after->toString()));

        return \array_slice($rest, 0, $limit);
    }
}
