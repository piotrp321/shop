<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\VariantSummaries;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Tax\VatRates;

/**
 * A variant added to a promotion exists in Catalog and has a current catalog price, so the price lock (Q13) never
 * leaves a variant of a promotion without a price. Only the added variants are asked: a variant deleted from the
 * catalog after it joined the promotion does not block a change of its name or end.
 */
final readonly class PromotionVariantRules
{
    public function __construct(
        private VariantSummaries $catalog,
        private VariantPriceRepository $prices,
        private VatRates $rates,
    ) {
    }

    /**
     * @param list<VariantId> $added
     *
     * @throws InvalidPromotionVariants
     */
    public function assertCanJoin(array $added): void
    {
        if ([] === $added) {
            return;
        }

        $known = $this->catalog->of($added);
        $current = [];
        $knownIds = array_values(array_filter($added, static fn (VariantId $id): bool => isset($known[$id->toString()])));
        foreach ($this->prices->findMany($knownIds) as $price) {
            if ($price->isCurrent($this->rates)) {
                $current[$price->id()->toString()] = true;
            }
        }

        $unknown = [];
        $withoutPrice = [];
        foreach ($added as $id) {
            $key = $id->toString();
            if (!isset($known[$key])) {
                $unknown[] = $key;
            } elseif (!isset($current[$key])) {
                $withoutPrice[] = $key;
            }
        }
        if ([] !== $unknown || [] !== $withoutPrice) {
            throw new InvalidPromotionVariants($unknown, $withoutPrice);
        }
    }
}
