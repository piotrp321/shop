<?php

declare(strict_types=1);

namespace App\Pricing\Application\TaxReconciliation;

use App\Pricing\Application\Port\CatalogProduct;
use App\Pricing\Application\Port\PersistenceSession;
use App\Pricing\Application\Port\ProductsForPricing;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Price\ProductPriceRepository;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRates;

/**
 * Goes through all prices and finds those whose rate is not the current rate of their class or whose class is not
 * the class of the product in Catalog (`pricing:reconcile-tax`). With $apply each such price is repriced (the same
 * gross, the class from Catalog and its current rate, source `tax_reconciliation`), each in its own transaction
 * (one save). A save refused by a concurrent change is retried once on the price read again; a second refusal
 * leaves the price as it is.
 *
 * The prices and the products of Catalog are read in batches, both ordered by product id, and walked side by side,
 * so neither is ever held in memory. A price of a product Catalog does not have keeps its own class.
 */
final readonly class ReconcileTax
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private ProductPriceRepository $prices,
        private ProductsForPricing $products,
        private VatRates $rates,
        private PersistenceSession $session,
    ) {
    }

    /**
     * Any failure other than a refused save (e.g. a lost database connection) stops the run; the prices saved so
     * far keep their change.
     *
     * @return \Generator<int, TaxDiscrepancy, mixed, int> the discrepancies in product id order, as they are dealt
     *                                                     with; returns the number of prices checked
     */
    public function __invoke(bool $apply): \Generator
    {
        $catalog = $this->catalogProducts();
        $checked = 0;
        $after = null;
        do {
            $batch = $this->prices->page($after, self::BATCH_SIZE);
            foreach ($batch as $price) {
                ++$checked;
                $discrepancy = $this->reconcile($price, $this->catalogClassOf($catalog, $price->id()), $apply);
                if (null !== $discrepancy) {
                    yield $discrepancy;
                }
            }
            $after = [] === $batch ? null : $batch[\count($batch) - 1]->id();
            // Forget what the batch read and wrote.
            $this->session->reset();
        } while (self::BATCH_SIZE === \count($batch));

        return $checked;
    }

    private function reconcile(ProductPrice $price, ?TaxClass $catalogClass, bool $apply): ?TaxDiscrepancy
    {
        $discrepancy = $this->reprice($price, $catalogClass);
        if (null === $discrepancy || !$apply || TaxDiscrepancyOutcome::NoRate === $discrepancy->outcome) {
            return $discrepancy;
        }
        if ($this->saved($price)) {
            return $discrepancy->withOutcome(TaxDiscrepancyOutcome::Fixed);
        }

        // Read again in a fresh session: the price as the concurrent change left it.
        $price = $this->prices->find($price->id());
        $discrepancy = null === $price ? null : $this->reprice($price, $catalogClass);
        if (null === $price || null === $discrepancy || TaxDiscrepancyOutcome::NoRate === $discrepancy->outcome) {
            return $discrepancy;
        }

        return $discrepancy->withOutcome($this->saved($price) ? TaxDiscrepancyOutcome::Fixed : TaxDiscrepancyOutcome::ModifiedConcurrently);
    }

    /**
     * Reprices the price (not saved) with the class it should have.
     *
     * @return TaxDiscrepancy|null null when the price already is what it should be
     */
    private function reprice(ProductPrice $price, ?TaxClass $catalogClass): ?TaxDiscrepancy
    {
        $storedClass = $price->taxClass();
        $storedRate = $price->breakdown()->rate();
        $expectedClass = $catalogClass ?? $storedClass;

        try {
            if (!$price->reprice($expectedClass, $this->rates, PriceChangeOrigin::taxReconciliation())) {
                return null;
            }
        } catch (TaxClassWithoutRate) {
            return new TaxDiscrepancy($price->id(), $storedClass, $storedRate, $expectedClass, null, TaxDiscrepancyOutcome::NoRate);
        }

        return new TaxDiscrepancy($price->id(), $storedClass, $storedRate, $price->taxClass(), $price->breakdown()->rate(), TaxDiscrepancyOutcome::Found);
    }

    /**
     * @return bool false when a concurrent change refused the save (the session is reset then)
     */
    private function saved(ProductPrice $price): bool
    {
        try {
            $this->prices->save($price);

            return true;
        } catch (PriceModifiedConcurrently) {
            $this->session->reset();

            return false;
        }
    }

    /**
     * Moves the catalog walk up to the product and returns its class, null when Catalog has no such product.
     *
     * @param \Generator<int, CatalogProduct> $catalog
     */
    private function catalogClassOf(\Generator $catalog, ProductId $productId): ?TaxClass
    {
        $id = $productId->toString();
        // Canonical lower-case UUIDs compare as strings in the order PostgreSQL sorts uuid.
        while ($catalog->valid() && strcmp($catalog->current()->id->toString(), $id) < 0) {
            $catalog->next();
        }

        return $catalog->valid() && $catalog->current()->id->equals($productId) ? $catalog->current()->taxClass : null;
    }

    /**
     * @return \Generator<int, CatalogProduct>
     */
    private function catalogProducts(): \Generator
    {
        $after = null;
        do {
            $batch = $this->products->page($after, self::BATCH_SIZE);
            yield from $batch;
            $after = [] === $batch ? null : $batch[\count($batch) - 1]->id;
        } while (self::BATCH_SIZE === \count($batch));
    }
}
