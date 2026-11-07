<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Application\Port\PromotionBoundaries;
use App\Pricing\Application\Port\PromotionWriteLock;
use App\Pricing\Domain\Promotion\InvalidPromotion;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionOverlap;
use App\Pricing\Domain\Promotion\PromotionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Plans a promotion in the transaction of command.bus, under the exclusive lock of the promotion writes: the clock is
 * read after the lock, the promotion checks its own rules, its variants must exist and have a current price, and no
 * other promotion of an overlapping period may have one of them (Q4). Then the boundaries of its period are
 * scheduled.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class PlanPromotionHandler
{
    public function __construct(
        private PromotionRepository $promotions,
        private PromotionVariantRules $variantRules,
        private PromotionWriteLock $lock,
        private Clock $clock,
        private PromotionBoundaries $boundaries,
    ) {
    }

    /**
     * @throws InvalidPromotion
     * @throws InvalidPromotionVariants
     * @throws PromotionOverlap
     */
    public function __invoke(PlanPromotion $command): void
    {
        $this->lock->exclusive();
        $now = $this->clock->now();

        $variantIds = PromotionWrites::variantIds($command->variantIds);
        $promotion = Promotion::plan(
            PromotionId::fromString($command->id),
            $command->name,
            PercentOff::of($command->percentOff),
            $command->startsAt,
            $command->endsAt,
            $variantIds,
            PromotionWrites::actor($command->actorId, $command->actorName),
            $now,
        );
        $this->variantRules->assertCanJoin($variantIds);
        PromotionWrites::assertNoOverlap($this->promotions, $promotion);

        $this->promotions->save($promotion);
        $this->boundaries->promotionSaved($promotion, null, $now);
    }
}
