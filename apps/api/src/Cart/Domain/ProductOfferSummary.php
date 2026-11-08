<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * The offer of a product of the list, computed from the variants that can be bought (VariantOffer::isPurchasable()):
 * `fromGross` is their lowest gross price, `fromPromotion` the promotion of the variant that gives it (of variants of
 * the same price, the first in order), `hasPriceRange` whether their highest price differs from it, and `level` the
 * best of their levels. Without such a variant (also a product without variants) there is no price, no promotion and
 * the product is out of stock. The one place of this rule.
 */
final readonly class ProductOfferSummary
{
    private function __construct(
        public ?Money $fromGross,
        public bool $hasPriceRange,
        public StockLevel $level,
        public ?VariantPromotion $fromPromotion,
    ) {
    }

    /**
     * @param list<VariantOffer> $variants
     */
    public static function of(array $variants): self
    {
        $lowest = null;
        $fromPromotion = null;
        $highest = null;
        $level = null;
        foreach ($variants as $variant) {
            if (!$variant->isPurchasable() || null === $variant->gross) {
                continue;
            }
            if (null === $lowest || $variant->gross->amount() < $lowest->amount()) {
                $lowest = $variant->gross;
                $fromPromotion = $variant->promotion;
            }
            if (null === $highest || $variant->gross->amount() > $highest->amount()) {
                $highest = $variant->gross;
            }
            if (null === $level || $variant->level->isBetterThan($level)) {
                $level = $variant->level;
            }
        }

        if (null === $lowest || null === $highest || null === $level) {
            return new self(null, false, StockLevel::OutOfStock, null);
        }

        return new self($lowest, $highest->amount() !== $lowest->amount(), $level, $fromPromotion);
    }
}
