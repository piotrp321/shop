<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Application\Port\PromotionBoundaries;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A boundary of a promotion period has come: publishes PromotionPricesChanged for every product of the promotion, in
 * the transaction of command.bus (the outbox rows together or not at all), outside the transaction of the panel.
 *
 * It decides on the stored promotion: an unknown one (a deleted planned promotion) or one whose current start and end
 * are not the moment of the message (the period changed and another message carries the new boundary) is a message
 * handled without effect. A message that came too early (the transport stores the moment to the second and compares
 * it with the system time) is sent again for a second after the boundary.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class PromotionBoundaryReachedHandler
{
    private const string RETRY_DELAY = '+1 second';

    public function __construct(
        private PromotionRepository $promotions,
        private PromotionPriceEvents $priceEvents,
        private PromotionBoundaries $boundaries,
        private Clock $clock,
    ) {
    }

    public function __invoke(PromotionBoundaryReached $message): void
    {
        $promotion = $this->promotions->find(PromotionId::fromString($message->promotionId));
        if (null === $promotion || !self::isBoundaryOf($promotion, $message->at)) {
            return;
        }

        $now = $this->clock->now();
        if ($now < $message->at) {
            $this->boundaries->redeliverBoundary($promotion->id(), $message->at, $message->at->modify(self::RETRY_DELAY));

            return;
        }

        $this->priceEvents->publishFor($promotion, $now);
    }

    private static function isBoundaryOf(Promotion $promotion, \DateTimeImmutable $at): bool
    {
        $period = $promotion->period();

        return $at == $period->startsAt() || $at == $period->endsAt();
    }
}
