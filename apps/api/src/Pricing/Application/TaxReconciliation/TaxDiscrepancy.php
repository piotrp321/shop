<?php

declare(strict_types=1);

namespace App\Pricing\Application\TaxReconciliation;

use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;

/**
 * A price whose class or rate is not the one it should have: the class of the product in Catalog (the price's own
 * class when Catalog has no such product) at the current rate of that class.
 */
final readonly class TaxDiscrepancy
{
    /**
     * @param VatRate|null $expectedRate null when the expected class has no rate
     */
    public function __construct(
        public ProductId $productId,
        public TaxClass $storedClass,
        public VatRate $storedRate,
        public TaxClass $expectedClass,
        public ?VatRate $expectedRate,
        public TaxDiscrepancyOutcome $outcome,
    ) {
    }

    public function withOutcome(TaxDiscrepancyOutcome $outcome): self
    {
        return new self($this->productId, $this->storedClass, $this->storedRate, $this->expectedClass, $this->expectedRate, $outcome);
    }

    public function isFixed(): bool
    {
        return TaxDiscrepancyOutcome::Fixed === $this->outcome;
    }
}
