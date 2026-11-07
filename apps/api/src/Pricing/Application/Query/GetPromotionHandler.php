<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Application\Port\VariantSummaries;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Promotion\PromotionalPrice;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionNotFound;
use App\Pricing\Domain\Promotion\PromotionRepository;
use App\Pricing\Domain\Promotion\PromotionStatus;
use App\Pricing\Domain\Tax\VatRates;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetPromotionHandler
{
    public function __construct(
        private PromotionRepository $promotions,
        private VariantSummaries $catalog,
        private VariantPriceRepository $prices,
        private VatRates $rates,
        private Clock $clock,
    ) {
    }

    /**
     * The variants from Catalog (in batches) and their prices in one read. An ended promotion has no promotional
     * prices: the catalog price may have changed since, and what was paid is in the orders.
     *
     * @throws PromotionNotFound also for an id that is no UUID
     */
    public function __invoke(GetPromotion $query): PromotionDetails
    {
        try {
            $id = PromotionId::fromString($query->id);
        } catch (\InvalidArgumentException) {
            throw new PromotionNotFound();
        }
        $promotion = $this->promotions->find($id) ?? throw new PromotionNotFound();
        $status = $promotion->status($this->clock->now());

        $variantIds = $promotion->variantIds();
        $summaries = $this->catalog->of($variantIds);
        $current = [];
        foreach ($this->prices->findMany($variantIds) as $price) {
            if ($price->isCurrent($this->rates)) {
                $current[$price->id()->toString()] = $price->breakdown()->gross();
            }
        }

        $variants = [];
        foreach ($variantIds as $variantId) {
            $key = $variantId->toString();
            $summary = $summaries[$key] ?? null;
            $gross = $current[$key] ?? null;
            $variants[] = new PromotionVariantView(
                $key,
                $summary?->productId,
                $summary?->productName,
                $summary?->label,
                $gross?->amount(),
                null === $gross || PromotionStatus::Ended === $status ? null : PromotionalPrice::apply($gross, $promotion->percentOff())->amount(),
            );
        }

        return new PromotionDetails(
            $promotion->id()->toString(),
            $promotion->name(),
            $promotion->percentOff()->value(),
            $promotion->period()->startsAt(),
            $promotion->period()->endsAt(),
            $status,
            \count($variantIds),
            $promotion->version(),
            $promotion->updatedAt(),
            $promotion->updatedBy()?->name(),
            $variants,
        );
    }
}
