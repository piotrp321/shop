<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Application\Port\ListedPromotion;
use App\Pricing\Application\Port\PromotionReader;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use App\Pricing\Domain\Promotion\PromotionStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListPromotionsHandler
{
    public function __construct(
        private PromotionReader $promotions,
        private Clock $clock,
    ) {
    }

    /**
     * The status filter and the status of each item are taken at one moment. One promotion more than the page is
     * read: its presence is the only thing that tells there is a next page, so the whole list is never counted.
     *
     * @throws InvalidPromotionCursor
     */
    public function __invoke(ListPromotions $query): PromotionList
    {
        $after = null === $query->after ? null : PromotionCursor::decode($query->after);
        $now = $this->clock->now();

        $promotions = $this->promotions->page($query->status, $now, $after, $query->perPage + 1);
        $next = null;
        if (\count($promotions) > $query->perPage) {
            $promotions = \array_slice($promotions, 0, $query->perPage);
            $next = PromotionCursor::after($promotions[$query->perPage - 1])->encode();
        }

        return new PromotionList(array_map(static fn (ListedPromotion $promotion): PromotionListItem => new PromotionListItem(
            $promotion->id,
            $promotion->name,
            $promotion->percentOff,
            $promotion->startsAt,
            $promotion->endsAt,
            PromotionStatus::of(PromotionPeriod::of($promotion->startsAt, $promotion->endsAt), $now),
            $promotion->variantCount,
            $promotion->version,
        ), $promotions), $next);
    }
}
