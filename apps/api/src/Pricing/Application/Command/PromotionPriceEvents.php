<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\VariantSummaries;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionPriceChangeSource;
use App\Pricing\Domain\Promotion\PromotionPricesChanged;
use App\Shared\Domain\Event\DomainEventPublisher;

/**
 * Publishes PromotionPricesChanged once per product of the variants of a promotion, in the order of its variants: a
 * product with 20 variants in the promotion is one reindex and one invalidation. The products come from Catalog
 * (VariantSummaries, in batches of its limit); a variant Catalog does not know (deleted, or of a draft) has no page
 * and no document in the store to refresh, so it is skipped.
 */
final readonly class PromotionPriceEvents
{
    public function __construct(
        private VariantSummaries $variants,
        private DomainEventPublisher $events,
    ) {
    }

    public function publishFor(Promotion $promotion, \DateTimeImmutable $now): void
    {
        $summaries = $this->variants->of($promotion->variantIds());
        $products = [];
        foreach ($promotion->variantIds() as $variantId) {
            $summary = $summaries[$variantId->toString()] ?? null;
            if (null !== $summary) {
                $products[$summary->productId] = true;
            }
        }

        $this->events->publish(...array_map(
            static fn (string $productId): PromotionPricesChanged => new PromotionPricesChanged($productId, PromotionPriceChangeSource::Promotion, $now),
            array_keys($products),
        ));
    }
}
