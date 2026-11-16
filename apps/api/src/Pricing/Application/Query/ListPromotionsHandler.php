<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Application\Port\ListedPromotion;
use App\Pricing\Application\Port\PromotionReader;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use App\Pricing\Domain\Promotion\PromotionStatus;
use App\Shared\Domain\BoundedCount;
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
     * read: its presence is what tells there is a next page. The first page (no cursor) also carries the bounded
     * total of the status filter: the number of its rows when there is no next page (nothing to count), otherwise
     * the reader counts, never the table whole.
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
            $total = null === $after ? $this->promotions->count($query->status, $now) : null;
        } else {
            $total = null === $after ? new BoundedCount(\count($promotions), false) : null;
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
        ), $promotions), $next, $total);
    }
}
