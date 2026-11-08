<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionPeriod;

/**
 * What follows a saved promotion at the boundaries of its period, in the transaction of the write: the moments its
 * prices start and stop being in force (PromotionBoundaryReached), and the refresh of its products when an active
 * promotion's end moves (RefreshPromotionProducts). A deleted promotion (only a planned one) needs nothing. The
 * messages wait in the database for their moment, also while no worker runs.
 */
interface PromotionBoundaries
{
    /**
     * @param PromotionPeriod|null $previousPeriod the period before the write; null for a new promotion
     * @param \DateTimeImmutable   $now            the moment of the write (read after the lock)
     */
    public function promotionSaved(Promotion $promotion, ?PromotionPeriod $previousPeriod, \DateTimeImmutable $now): void;

    /**
     * Sends PromotionBoundaryReached of the boundary again, due at the moment given (a message that came too early).
     */
    public function redeliverBoundary(PromotionId $promotionId, \DateTimeImmutable $boundary, \DateTimeImmutable $dueAt): void;
}
