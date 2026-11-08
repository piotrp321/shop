<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * The status of a code is never stored: it follows from its flag, its period and the moment it is asked at. A
 * disabled code is disabled whatever its period.
 */
enum DiscountCodeStatus: string
{
    case Disabled = 'disabled';
    case Planned = 'planned';
    case Active = 'active';
    case Expired = 'expired';

    public static function of(bool $enabled, ValidityPeriod $period, \DateTimeImmutable $now): self
    {
        return match (true) {
            !$enabled => self::Disabled,
            !$period->hasStartedAt($now) => self::Planned,
            $period->hasEndedAt($now) => self::Expired,
            default => self::Active,
        };
    }
}
