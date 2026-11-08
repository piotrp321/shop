<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\CategoryNotFound;
use App\Pricing\Application\Port\DirectoryPage;
use App\Pricing\Application\Port\DirectoryProduct;
use App\Pricing\Application\Port\DirectoryVariant;
use App\Pricing\Application\Port\InvalidPriceListCursor;
use App\Pricing\Application\Port\ProductDirectory;
use App\Pricing\Application\Port\ProductTaxClass;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Tax\TaxClass;

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
    /** @var list<string> the product id of every taxClassOf() call */
    public array $taxClassRequests = [];
    /** @var array<string, list<DirectoryVariant>> */
    private array $variants = [];
    /** @var array<string, string> product id => tax class code */
    private array $taxClasses = [];
    /** @var list<\Closure(): void> run one per taxClassOf() call, after the answer is read */
    private array $afterTaxClassReads = [];

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

    /**
     * Sets the current class of a product, e.g. a class changed in Catalog; taxClassOf() answers for products with a
     * class and variants.
     */
    public function withTaxClass(string $productId, string $taxClass): self
    {
        $this->taxClasses[$productId] = $taxClass;

        return $this;
    }

    /**
     * Plays a change in Catalog right after a taxClassOf() answer is read, e.g. a class changed concurrently.
     *
     * @param \Closure(): void $change
     */
    public function afterTaxClassRead(\Closure $change): self
    {
        $this->afterTaxClassReads[] = $change;

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

    public function taxClassOf(ProductId $productId): ?ProductTaxClass
    {
        $this->taxClassRequests[] = $productId->toString();
        $class = $this->taxClasses[$productId->toString()] ?? null;
        $variants = $this->variants[$productId->toString()] ?? null;
        $answer = null === $class || null === $variants ? null : new ProductTaxClass(
            $productId,
            TaxClass::fromString($class),
            array_map(static fn (DirectoryVariant $variant): VariantId => $variant->id, $variants),
        );
        $change = array_shift($this->afterTaxClassReads);
        if (null !== $change) {
            $change();
        }

        return $answer;
    }
}
