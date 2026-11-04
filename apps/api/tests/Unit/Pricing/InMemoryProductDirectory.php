<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\CategoryNotFound;
use App\Pricing\Application\Port\DirectoryPage;
use App\Pricing\Application\Port\DirectoryProduct;
use App\Pricing\Application\Port\DirectoryVariant;
use App\Pricing\Application\Port\InvalidPriceListCursor;
use App\Pricing\Application\Port\ProductDirectory;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\VariantId;

/**
 * Hands out the page it was given and the variants of the products it knows, and remembers what it was asked for.
 */
final class InMemoryProductDirectory implements ProductDirectory
{
    /** @var list<array{?string, ?string, ?string, int}> term, category id, cursor and page size of every call */
    public array $requests = [];
    /** @var list<list<string>> the product ids of every variantsOf() call */
    public array $variantRequests = [];
    public bool $categoryExists = true;
    public bool $cursorValid = true;
    /** @var array<string, list<DirectoryVariant>> */
    private array $variants = [];

    /**
     * @param list<DirectoryProduct> $products
     */
    public function __construct(private array $products = [], private ?string $next = null)
    {
    }

    /**
     * @param array<string, string> $labels variant id => label, in presentation order
     */
    public function withVariants(string $productId, array $labels): self
    {
        $this->variants[$productId] = array_map(
            static fn (string $id, string $label): DirectoryVariant => new DirectoryVariant(VariantId::fromString($id), $label),
            array_keys($labels),
            array_values($labels),
        );

        return $this;
    }

    public function page(?string $term, ?string $categoryId, ?string $after, int $perPage): DirectoryPage
    {
        $this->requests[] = [$term, $categoryId, $after, $perPage];
        if (!$this->categoryExists) {
            throw new CategoryNotFound();
        }
        if (!$this->cursorValid) {
            throw new InvalidPriceListCursor();
        }

        return new DirectoryPage($this->products, $this->next);
    }

    public function variantsOf(array $productIds): array
    {
        $this->variantRequests[] = array_map(static fn (ProductId $id): string => $id->toString(), $productIds);
        $found = [];
        foreach ($productIds as $id) {
            if (isset($this->variants[$id->toString()])) {
                $found[$id->toString()] = $this->variants[$id->toString()];
            }
        }

        return $found;
    }
}
