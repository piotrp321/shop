<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Promotion;

use App\Pricing\Application\Port\PromotionBoundaries;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionPeriod;

/**
 * Nothing follows the boundaries of a promotion yet: the storefront and the search index show its prices once they
 * read them again (at the end of their cache time).
 */
final readonly class NoPromotionBoundaries implements PromotionBoundaries
{
    public function promotionSaved(Promotion $promotion, ?PromotionPeriod $previousPeriod, \DateTimeImmutable $now): void
    {
    }

    public function redeliverBoundary(PromotionId $promotionId, \DateTimeImmutable $boundary, \DateTimeImmutable $dueAt): void
    {
    }
}
