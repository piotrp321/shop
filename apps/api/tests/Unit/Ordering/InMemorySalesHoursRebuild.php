<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\SalesHoursRebuild;
use App\Ordering\Application\Port\SalesTotals;

/**
 * Hours with sales to rebuild, in memory: the rebuilt hours are recorded in order; a hour may fail.
 */
final class InMemorySalesHoursRebuild implements SalesHoursRebuild
{
    /** @var list<string> the rebuilt hours (UTC, Y-m-d H:i), in order */
    public array $rebuilt = [];

    /**
     * @param array<string, SalesTotals> $hours the totals by hour (UTC, Y-m-d H:i)
     */
    public function __construct(private readonly array $hours, private readonly ?string $failingHour = null)
    {
    }

    public function nextHour(?\DateTimeImmutable $from): ?\DateTimeImmutable
    {
        $next = null;
        foreach (array_keys($this->hours) as $hour) {
            $start = new \DateTimeImmutable($hour.'+00:00');
            if ((null === $from || $start >= $from) && (null === $next || $start < $next)) {
                $next = $start;
            }
        }

        return $next;
    }

    public function rebuild(\DateTimeImmutable $hour): SalesTotals
    {
        $key = $hour->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i');
        if ($key === $this->failingHour) {
            throw new \RuntimeException('the database went away');
        }
        $this->rebuilt[] = $key;

        return $this->hours[$key];
    }
}
