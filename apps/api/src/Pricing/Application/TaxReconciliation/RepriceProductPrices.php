<?php

declare(strict_types=1);

namespace App\Pricing\Application\TaxReconciliation;

use App\Pricing\Application\Port\PersistenceSession;
use App\Pricing\Application\Port\ProductDirectory;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Tax\VatRates;

/**
 * Brings the prices of a product's variants to the product's current tax class after a change of the class (decision
 * K6 of CATALOG-ADMIN): every price whose class is not the class of the product in Catalog is repriced by
 * {@see PriceReconciler} (the same gross, the class and its current rate, source `tax_reconciliation`, each price in
 * its own transaction, one retry after a refused save). A price already in the class is not written, even with an
 * outdated rate (that is for pricing:reconcile-tax).
 *
 * The class is read from Catalog, never taken from an event, so any number of changes delivered in any order, also
 * more than once, end with the prices in the class the product has. When anything was saved, the class is read again:
 * a change saved meanwhile may have been handled before these writes (and found the prices in its class), so a
 * different class is TaxClassChangedWhileRepricing, for a run that starts anew.
 */
final readonly class RepriceProductPrices
{
    private PriceReconciler $reconciler;

    public function __construct(
        private VariantPriceRepository $prices,
        private ProductDirectory $directory,
        VatRates $rates,
        PersistenceSession $session,
    ) {
        $this->reconciler = new PriceReconciler($prices, $rates, $session);
    }

    /**
     * @return list<TaxDiscrepancy> the prices not in the class of the product, as they were dealt with: fixed, left
     *                              by a second refused save (modified_concurrently) or left because the class has no
     *                              rate (no_rate); empty for a product Catalog does not know
     *
     * @throws TaxClassChangedWhileRepricing the class changed while the prices were saved; the saved prices stay
     */
    public function __invoke(ProductId $productId): array
    {
        $product = $this->directory->taxClassOf($productId);
        if (null === $product || [] === $product->variantIds) {
            return [];
        }

        $discrepancies = [];
        $saved = false;
        foreach ($this->prices->findMany($product->variantIds) as $price) {
            if ($price->taxClass()->equals($product->taxClass)) {
                continue;
            }
            $discrepancy = $this->reconciler->reconcile($price, $product->taxClass, apply: true);
            if (null !== $discrepancy) {
                $discrepancies[] = $discrepancy;
                $saved = $saved || $discrepancy->isFixed();
            }
        }

        if ($saved) {
            $now = $this->directory->taxClassOf($productId);
            if (null !== $now && !$now->taxClass->equals($product->taxClass)) {
                throw new TaxClassChangedWhileRepricing();
            }
        }

        return $discrepancies;
    }
}
