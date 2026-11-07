<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Application\Port\PromotionWriteLock;
use App\Pricing\Domain\Promotion\PromotionModifiedConcurrently;
use App\Pricing\Domain\Promotion\PromotionNotEditable;
use App\Pricing\Domain\Promotion\PromotionNotFound;
use App\Pricing\Domain\Promotion\PromotionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes a planned promotion in the transaction of command.bus, under the exclusive lock of the promotion writes. Its
 * prices were never in force, so nothing else follows.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeletePromotionHandler
{
    public function __construct(
        private PromotionRepository $promotions,
        private PromotionWriteLock $lock,
        private Clock $clock,
    ) {
    }

    /**
     * @throws PromotionNotFound
     * @throws PromotionModifiedConcurrently
     * @throws PromotionNotEditable          unless the promotion is planned
     */
    public function __invoke(DeletePromotion $command): void
    {
        $this->lock->exclusive();
        $now = $this->clock->now();

        $promotion = PromotionWrites::load($this->promotions, $command->id, $command->expectedVersion);
        $promotion->assertDeletable($now);
        $this->promotions->delete($promotion);
    }
}
