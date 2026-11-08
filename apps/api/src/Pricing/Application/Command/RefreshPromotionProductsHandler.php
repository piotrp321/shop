<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionRepository;
use App\Pricing\Domain\Promotion\PromotionStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The end of an active promotion moved: publishes PromotionPricesChanged for every product of the promotion, in the
 * transaction of command.bus, like a reached boundary. An unknown or an ended promotion needs nothing (its end
 * boundary refreshed the products already).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class RefreshPromotionProductsHandler
{
    public function __construct(
        private PromotionRepository $promotions,
        private PromotionPriceEvents $priceEvents,
        private Clock $clock,
    ) {
    }

    public function __invoke(RefreshPromotionProducts $message): void
    {
        $promotion = $this->promotions->find(PromotionId::fromString($message->promotionId));
        if (null === $promotion) {
            return;
        }

        $now = $this->clock->now();
        if (PromotionStatus::Ended === $promotion->status($now)) {
            return;
        }

        $this->priceEvents->publishFor($promotion, $now);
    }
}
