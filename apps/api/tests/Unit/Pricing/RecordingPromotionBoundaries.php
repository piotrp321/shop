<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\PromotionBoundaries;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionPeriod;

final class RecordingPromotionBoundaries implements PromotionBoundaries
{
    /** @var list<array{string, PromotionPeriod, PromotionPeriod|null, \DateTimeImmutable}> promotion id, its period, the period before, now */
    public array $saved = [];

    public function promotionSaved(Promotion $promotion, ?PromotionPeriod $previousPeriod, \DateTimeImmutable $now): void
    {
        $this->saved[] = [$promotion->id()->toString(), $promotion->period(), $previousPeriod, $now];
    }
}
