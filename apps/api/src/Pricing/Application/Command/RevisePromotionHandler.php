<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Application\Port\PromotionBoundaries;
use App\Pricing\Application\Port\PromotionWriteLock;
use App\Pricing\Domain\Promotion\InvalidPromotion;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionModifiedConcurrently;
use App\Pricing\Domain\Promotion\PromotionNotEditable;
use App\Pricing\Domain\Promotion\PromotionNotFound;
use App\Pricing\Domain\Promotion\PromotionOverlap;
use App\Pricing\Domain\Promotion\PromotionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Changes a promotion in the transaction of command.bus, under the exclusive lock of the promotion writes: the version
 * first, then the rules of the promotion for its status at now (read after the lock), the variants it gains (they must
 * exist and have a current price; the ones it keeps are not asked) and the overlaps (Q4). Then the boundaries of the
 * new period are scheduled.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class RevisePromotionHandler
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
     * @throws PromotionNotFound
     * @throws PromotionModifiedConcurrently
     * @throws PromotionNotEditable
     * @throws InvalidPromotion
     * @throws InvalidPromotionVariants
     * @throws PromotionOverlap
     */
    public function __invoke(RevisePromotion $command): void
    {
        $this->lock->exclusive();
        $now = $this->clock->now();

        $promotion = PromotionWrites::load($this->promotions, $command->id, $command->expectedVersion);
        $variantIds = PromotionWrites::variantIds($command->variantIds);
        $added = $promotion->newVariantsAmong($variantIds);
        $previousPeriod = $promotion->period();
        $promotion->revise(
            $command->name,
            PercentOff::of($command->percentOff),
            $command->startsAt,
            $command->endsAt,
            $variantIds,
            PromotionWrites::actor($command->actorId, $command->actorName),
            $now,
        );
        $this->variantRules->assertCanJoin($added);
        PromotionWrites::assertNoOverlap($this->promotions, $promotion);

        $this->promotions->save($promotion);
        $this->boundaries->promotionSaved($promotion, $previousPeriod, $now, true);
    }
}
