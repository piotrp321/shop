<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRates;

/**
 * The catalog price of one product variant: the breakdown at the rate of its tax class, and the history entries of the
 * changes not saved yet.
 *
 * Every change of the gross, net, VAT, rate or class records one history entry; a change to the identical set
 * records nothing and keeps the version. The version is the one the state will have once saved: a new price has
 * version 1, and the changes made before the next save share one version step, as they are saved by one write.
 * They share one VariantPriceChanged too, with the source of the last change: one event per saved write.
 */
final class VariantPrice
{
    /** @var list<PriceHistoryEntry> */
    private array $unsavedHistory = [];

    private ?VariantPriceChanged $unsavedChange = null;

    private function __construct(
        private readonly VariantId $id,
        private PriceBreakdown $breakdown,
        private TaxClass $taxClass,
        private int $version,
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @throws TaxClassWithoutRate
     */
    public static function create(VariantId $id, GrossPrice $gross, TaxClass $taxClass, VatRates $rates, PriceChangeOrigin $origin): self
    {
        $changedAt = new \DateTimeImmutable();
        $price = new self($id, PriceBreakdown::fromGross($gross, $rates->rateFor($taxClass)), $taxClass, 1, $changedAt);
        $price->record($changedAt, $origin);

        return $price;
    }

    /**
     * Restores a stored price without recording history.
     */
    public static function reconstitute(VariantId $id, PriceBreakdown $breakdown, TaxClass $taxClass, int $version, \DateTimeImmutable $updatedAt): self
    {
        return new self($id, $breakdown, $taxClass, $version, $updatedAt);
    }

    /**
     * A new gross at the current rate of the price's class (which also fixes an outdated rate).
     *
     * @return bool false when the result is identical to the current price
     *
     * @throws TaxClassWithoutRate
     */
    public function changeGross(GrossPrice $gross, VatRates $rates, PriceChangeOrigin $origin): bool
    {
        return $this->apply(PriceBreakdown::fromGross($gross, $rates->rateFor($this->taxClass)), $this->taxClass, $origin);
    }

    /**
     * The same gross, with the class given and its current rate.
     *
     * @return bool false when the result is identical to the current price
     *
     * @throws TaxClassWithoutRate
     */
    public function reprice(TaxClass $taxClass, VatRates $rates, PriceChangeOrigin $origin): bool
    {
        return $this->apply(PriceBreakdown::fromGross($this->breakdown->gross(), $rates->rateFor($taxClass)), $taxClass, $origin);
    }

    /**
     * Whether the stored rate is the rate of the price's class today. A class without a rate is not current.
     */
    public function isCurrent(VatRates $rates): bool
    {
        try {
            return $rates->rateFor($this->taxClass)->equals($this->breakdown->rate());
        } catch (TaxClassWithoutRate) {
            return false;
        }
    }

    /**
     * @return list<PriceHistoryEntry> the entries recorded since the last call, oldest first
     */
    public function releaseHistory(): array
    {
        $entries = $this->unsavedHistory;
        $this->unsavedHistory = [];

        return $entries;
    }

    /**
     * @return list<VariantPriceChanged> the change recorded since the last call (at most one), then nothing
     */
    public function releaseEvents(): array
    {
        $change = $this->unsavedChange;
        $this->unsavedChange = null;

        return null === $change ? [] : [$change];
    }

    public function id(): VariantId
    {
        return $this->id;
    }

    public function breakdown(): PriceBreakdown
    {
        return $this->breakdown;
    }

    public function taxClass(): TaxClass
    {
        return $this->taxClass;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function apply(PriceBreakdown $breakdown, TaxClass $taxClass, PriceChangeOrigin $origin): bool
    {
        if ($breakdown->equals($this->breakdown) && $taxClass->equals($this->taxClass)) {
            return false;
        }

        if ([] === $this->unsavedHistory) {
            ++$this->version;
        }
        $this->breakdown = $breakdown;
        $this->taxClass = $taxClass;
        $this->updatedAt = new \DateTimeImmutable();
        $this->record($this->updatedAt, $origin);

        return true;
    }

    private function record(\DateTimeImmutable $changedAt, PriceChangeOrigin $origin): void
    {
        $this->unsavedHistory[] = new PriceHistoryEntry($this->id, $this->breakdown, $this->taxClass, $changedAt, $origin);
        $this->unsavedChange = new VariantPriceChanged($this->id->toString(), $origin->source(), $changedAt);
    }
}
