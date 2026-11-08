<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\PromotionBoundaries;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionPeriod;

final class RecordingPromotionBoundaries implements PromotionBoundaries
{
    /** @var list<array{string, PromotionPeriod, PromotionPeriod|null, \DateTimeImmutable}> promotion id, its period, the period before, now */
    public array $saved = [];
    /** @var list<array{string, \DateTimeImmutable, \DateTimeImmutable}> promotion id, the boundary, the moment it is due */
    public array $redelivered = [];

    public function promotionSaved(Promotion $promotion, ?PromotionPeriod $previousPeriod, \DateTimeImmutable $now): void
    {
        $this->saved[] = [$promotion->id()->toString(), $promotion->period(), $previousPeriod, $now];
    }

    public function redeliverBoundary(PromotionId $promotionId, \DateTimeImmutable $boundary, \DateTimeImmutable $dueAt): void
    {
        $this->redelivered[] = [$promotionId->toString(), $boundary, $dueAt];
    }
}
