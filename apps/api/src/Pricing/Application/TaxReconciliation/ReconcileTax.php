<?php

declare(strict_types=1);

namespace App\Pricing\Application\TaxReconciliation;

use App\Pricing\Application\Port\CatalogVariant;
use App\Pricing\Application\Port\PersistenceSession;
use App\Pricing\Application\Port\VariantsForPricing;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRates;

/**
 * Goes through all prices and finds those whose rate is not the current rate of their class or whose class is not
 * the class of the variant's product in Catalog (`pricing:reconcile-tax`). With $apply each such price is repriced
 * (the same gross, the class from Catalog and its current rate, source `tax_reconciliation`) by {@see PriceReconciler},
 * each in its own transaction (one save). A save refused by a concurrent change is retried once on the price read
 * again; a second refusal leaves the price as it is.
 *
 * The prices and the variants of Catalog (ListVariantIdentities) are read in batches, both ordered by variant id, and
 * walked side by side, so neither is ever held in memory. A price of a variant Catalog does not have keeps its own
 * class.
 */
final readonly class ReconcileTax
{
    private const int BATCH_SIZE = 500;

    private PriceReconciler $reconciler;

    public function __construct(
        private VariantPriceRepository $prices,
        private VariantsForPricing $variants,
        VatRates $rates,
        private PersistenceSession $session,
    ) {
        $this->reconciler = new PriceReconciler($prices, $rates, $session);
    }

    /**
     * Any failure other than a refused save (e.g. a lost database connection) stops the run; the prices saved so
     * far keep their change.
     *
     * @return \Generator<int, TaxDiscrepancy, mixed, int> the discrepancies in variant id order, as they are dealt
     *                                                     with; returns the number of prices checked
     */
    public function __invoke(bool $apply): \Generator
    {
        $catalog = $this->catalogVariants();
        $checked = 0;
        $after = null;
        do {
            $batch = $this->prices->page($after, self::BATCH_SIZE);
            foreach ($batch as $price) {
                ++$checked;
                $discrepancy = $this->reconciler->reconcile($price, $this->catalogClassOf($catalog, $price->id()), $apply);
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

    /**
     * Moves the catalog walk up to the variant and returns its class, null when Catalog has no such variant.
     *
     * @param \Generator<int, CatalogVariant> $catalog
     */
    private function catalogClassOf(\Generator $catalog, VariantId $variantId): ?TaxClass
    {
        $id = $variantId->toString();
        // Canonical lower-case UUIDs compare as strings in the order PostgreSQL sorts uuid.
        while ($catalog->valid() && strcmp($catalog->current()->id->toString(), $id) < 0) {
            $catalog->next();
        }

        return $catalog->valid() && $catalog->current()->id->equals($variantId) ? $catalog->current()->taxClass : null;
    }

    /**
     * @return \Generator<int, CatalogVariant>
     */
    private function catalogVariants(): \Generator
    {
        $after = null;
        do {
            $batch = $this->variants->page($after, self::BATCH_SIZE);
            yield from $batch;
            $after = [] === $batch ? null : $batch[\count($batch) - 1]->id;
        } while (self::BATCH_SIZE === \count($batch));
    }
}
