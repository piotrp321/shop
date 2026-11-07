<?php

declare(strict_types=1);

namespace App\Pricing\Application\TaxReconciliation;

use App\Pricing\Application\Port\PersistenceSession;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRates;

/**
 * Reprices one price with the class it should have and its current rate (the same gross, source
 * `tax_reconciliation`) and, when asked, saves it in its own transaction (one save). A save refused by a concurrent
 * change is retried once on the price read again in a fresh session; a second refusal leaves the price as it is.
 *
 * The loop of pricing:reconcile-tax ({@see ReconcileTax}).
 */
final readonly class PriceReconciler
{
    public function __construct(
        private VariantPriceRepository $prices,
        private VatRates $rates,
        private PersistenceSession $session,
    ) {
    }

    /**
     * @param TaxClass|null $expectedClass the class the price should have; null keeps the price's own class
     *
     * @return TaxDiscrepancy|null null when the price already is what it should be, also when read again after a
     *                             refused save
     */
    public function reconcile(VariantPrice $price, ?TaxClass $expectedClass, bool $apply): ?TaxDiscrepancy
    {
        $discrepancy = $this->reprice($price, $expectedClass);
        if (null === $discrepancy || !$apply || TaxDiscrepancyOutcome::NoRate === $discrepancy->outcome) {
            return $discrepancy;
        }
        if ($this->saved($price)) {
            return $discrepancy->withOutcome(TaxDiscrepancyOutcome::Fixed);
        }

        // Read again in a fresh session: the price as the concurrent change left it.
        $price = $this->prices->find($price->id());
        $discrepancy = null === $price ? null : $this->reprice($price, $expectedClass);
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
    private function reprice(VariantPrice $price, ?TaxClass $expectedClass): ?TaxDiscrepancy
    {
        $storedClass = $price->taxClass();
        $storedRate = $price->breakdown()->rate();
        $expectedClass ??= $storedClass;

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
    private function saved(VariantPrice $price): bool
    {
        try {
            $this->prices->save($price);

            return true;
        } catch (PriceModifiedConcurrently) {
            $this->session->reset();

            return false;
        }
    }
}
