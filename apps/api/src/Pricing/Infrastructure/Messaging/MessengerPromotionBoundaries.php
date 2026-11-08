<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Messaging;

use App\Pricing\Application\Command\PromotionBoundaryReached;
use App\Pricing\Application\Command\RefreshPromotionProducts;
use App\Pricing\Application\Port\PromotionBoundaries;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Sends the messages of the promotion boundaries on command.bus; the routing puts them on the Doctrine transport
 * `promotions`, on the connection of the caller's transaction, so the rows are written or rolled back with the
 * promotion. The transport stores available_at to the second (rounded up) and compares it with the system time.
 *
 * - PromotionBoundaryReached for every boundary of the saved period at or after now (a start "from now" and an end
 *   "end now" are the start of now, so they have one too), delayed until it. A boundary the period had before the
 *   write is sent again: its earlier message may have ended in `failed`, and a duplicate is harmless (the handler
 *   and the consumers of PromotionPricesChanged are idempotent).
 * - RefreshPromotionProducts without a delay when the write moved the end of an active promotion to another moment
 *   after the start of now: the products show the end, and no boundary comes before the new end. An end moved to the
 *   start of now ("end now") is refreshed by its boundary within a couple of seconds (available_at is rounded up).
 */
final readonly class MessengerPromotionBoundaries implements PromotionBoundaries
{
    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    public function promotionSaved(Promotion $promotion, ?PromotionPeriod $previousPeriod, \DateTimeImmutable $now): void
    {
        $period = $promotion->period();
        foreach ([$period->startsAt(), $period->endsAt()] as $boundary) {
            if ($boundary >= $now) {
                $this->sendBoundary($promotion->id(), $boundary, $boundary);
            }
        }

        if (null !== $previousPeriod
            && $previousPeriod->isActiveAt($now)
            && $period->endsAt() != $previousPeriod->endsAt()
            && $period->endsAt() > Promotion::startOfNow($now)
        ) {
            $this->commandBus->dispatch(new RefreshPromotionProducts($promotion->id()->toString()));
        }
    }

    public function redeliverBoundary(PromotionId $promotionId, \DateTimeImmutable $boundary, \DateTimeImmutable $dueAt): void
    {
        $this->sendBoundary($promotionId, $boundary, $dueAt);
    }

    private function sendBoundary(PromotionId $promotionId, \DateTimeImmutable $boundary, \DateTimeImmutable $dueAt): void
    {
        $this->commandBus->dispatch(new PromotionBoundaryReached($promotionId->toString(), $boundary), [DelayStamp::delayUntil($dueAt)]);
    }
}
