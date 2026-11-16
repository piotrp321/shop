<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\SalesTotals;

/**
 * The sales of one period of the dashboard. Amounts in grosze. The average is the revenue per order, rounded to the
 * grosz (null without orders); the change is the change of the revenue against the period before, in basis points
 * (+12,5% is 1250; null when the period before had no revenue). Both are rounded half away from zero. All four are
 * null for a period without a completed hour (today between 0:00 and 1:00).
 */
final readonly class PeriodSales
{
    public function __construct(
        public ?int $revenue,
        public ?int $orderCount,
        public ?int $averageOrderValue,
        public ?int $changeBasisPoints,
    ) {
    }

    public static function compared(SalesTotals $current, SalesTotals $previous): self
    {
        return new self(
            $current->gross,
            $current->orderCount,
            0 === $current->orderCount ? null : self::rounded($current->gross, $current->orderCount),
            0 === $previous->gross ? null : self::rounded(($current->gross - $previous->gross) * 10_000, $previous->gross),
        );
    }

    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    /**
     * $dividend / $divisor rounded half away from zero, for a positive divisor.
     */
    private static function rounded(int $dividend, int $divisor): int
    {
        return $dividend >= 0
            ? intdiv(2 * $dividend + $divisor, 2 * $divisor)
            : -intdiv(-2 * $dividend + $divisor, 2 * $divisor);
    }
}
