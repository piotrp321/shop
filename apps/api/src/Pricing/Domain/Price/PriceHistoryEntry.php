<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

use App\Pricing\Domain\Tax\TaxClass;

/**
 * A snapshot of a price right after a change: gross, net, VAT, rate and currency (the breakdown), the tax class,
 * the time and who made it. History is only ever appended to.
 */
final readonly class PriceHistoryEntry
{
    public function __construct(
        private VariantId $variantId,
        private PriceBreakdown $breakdown,
        private TaxClass $taxClass,
        private \DateTimeImmutable $changedAt,
        private PriceChangeOrigin $origin,
    ) {
    }

    public function variantId(): VariantId
    {
        return $this->variantId;
    }

    public function breakdown(): PriceBreakdown
    {
        return $this->breakdown;
    }

    public function taxClass(): TaxClass
    {
        return $this->taxClass;
    }

    public function changedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function origin(): PriceChangeOrigin
    {
        return $this->origin;
    }
}
