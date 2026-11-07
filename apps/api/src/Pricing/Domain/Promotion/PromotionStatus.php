<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

/**
 * The status of a promotion is never stored: it follows from its period and the moment it is asked at.
 */
enum PromotionStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Ended = 'ended';

    public static function of(PromotionPeriod $period, \DateTimeImmutable $now): self
    {
        return match (true) {
            $now < $period->startsAt() => self::Planned,
            $now < $period->endsAt() => self::Active,
            default => self::Ended,
        };
    }
}
