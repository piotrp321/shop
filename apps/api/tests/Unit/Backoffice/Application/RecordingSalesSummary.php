<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Application;

use App\Backoffice\Application\Port\PeriodFigures;
use App\Backoffice\Application\Port\SalesFigures;
use App\Backoffice\Application\Port\SalesSummary;

final class RecordingSalesSummary implements SalesSummary
{
    public int $calls = 0;

    public function __construct(private readonly SalesFigures $figures = new SalesFigures(
        new \DateTimeImmutable('2026-11-15 13:00:00+00:00'),
        'PLN',
        new PeriodFigures(15_001, 2, 7_501, 5_001),
        new PeriodFigures(95_002, 5, 19_000, null),
        new PeriodFigures(0, 0, null, null),
    ))
    {
    }

    public function summary(): SalesFigures
    {
        ++$this->calls;

        return $this->figures;
    }
}
