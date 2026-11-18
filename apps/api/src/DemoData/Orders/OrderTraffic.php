<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

/**
 * When the generated orders are placed (spec: "Zamówienia", "Identyfikatory, czas i determinizm"; P7): the traffic of
 * the three years before the anchor, growing from month to month, with a peak in November and December and the rhythm
 * of a day (Polish time), and the season of every department (skis in winter, kayaks in summer).
 *
 * The placement time is a non-decreasing function of the global index of the order: the inverse of the cumulative
 * traffic, sampled at the middle of the share of the index, over hourly buckets. So the numbers, which grow with the
 * index, grow with the time too, without a pass after the load, and every part computes the times of its own indexes.
 * The last order is placed an hour before the anchor (P8: every order is paid or cancelled by then).
 */
final class OrderTraffic
{
    public const int HISTORY_DAYS = 1_095;
    public const int END_BEFORE_SECONDS = 3_600;
    /** The traffic of the last hour against the first one. */
    private const float GROWTH = 2.5;
    private const int BUCKET_SECONDS = 3_600;
    private const string ZONE = 'Europe/Warsaw';
    /** January to December. */
    private const array MONTHS = [0.85, 0.75, 0.85, 0.95, 1.0, 0.95, 0.9, 0.9, 0.95, 1.05, 1.55, 1.75];
    /** Midnight to 23:00, Polish time. */
    private const array HOURS = [0.25, 0.15, 0.1, 0.08, 0.08, 0.12, 0.3, 0.55, 0.8, 0.95, 1.0, 1.05, 1.1, 1.05, 1.0, 1.0, 1.05, 1.15, 1.3, 1.5, 1.6, 1.5, 1.1, 0.6];
    /** The season of a department by the slug of the department, January to December; a department not listed has none. */
    private const array DEPARTMENTS = [
        'sporty-zimowe' => [2.4, 2.0, 1.1, 0.4, 0.15, 0.1, 0.1, 0.15, 0.3, 0.8, 1.8, 2.6],
        'sporty-wodne' => [0.2, 0.25, 0.5, 0.9, 1.5, 2.2, 2.5, 2.0, 0.9, 0.4, 0.25, 0.3],
        'rower' => [0.4, 0.5, 1.0, 1.5, 1.7, 1.6, 1.5, 1.3, 1.0, 0.7, 0.5, 0.6],
        'turystyka' => [0.6, 0.6, 0.8, 1.0, 1.3, 1.5, 1.6, 1.4, 1.0, 0.8, 0.7, 0.8],
        'bieganie' => [1.2, 1.0, 1.2, 1.2, 1.1, 1.0, 0.9, 0.9, 1.0, 1.0, 0.9, 0.9],
        'fitness' => [1.6, 1.3, 1.1, 1.0, 0.9, 0.8, 0.8, 0.8, 1.0, 1.0, 1.0, 1.0],
        'sporty-druzynowe' => [0.8, 0.8, 1.0, 1.1, 1.1, 1.0, 0.9, 1.0, 1.2, 1.1, 0.9, 0.9],
    ];

    private int $start;
    private int $end;
    /** @var list<float> the traffic of every bucket */
    private array $weights = [];
    /** @var list<float> the traffic before every bucket, and the total last */
    private array $cumulative = [0.0];
    private float $total;

    public function __construct(\DateTimeImmutable $anchor, private readonly int $orders)
    {
        if ($orders < 1) {
            throw new \InvalidArgumentException('The traffic needs at least one order.');
        }
        $this->start = $anchor->getTimestamp() - self::HISTORY_DAYS * 86_400;
        $this->end = $anchor->getTimestamp() - self::END_BEFORE_SECONDS;
        $zone = new \DateTimeZone(self::ZONE);
        $span = $this->end - $this->start;
        for ($at = $this->start; $at < $this->end; $at += self::BUCKET_SECONDS) {
            $local = new \DateTimeImmutable('@'.$at)->setTimezone($zone);
            $growth = 1.0 + (self::GROWTH - 1.0) * ($at - $this->start) / $span;
            $length = min(self::BUCKET_SECONDS, $this->end - $at) / self::BUCKET_SECONDS;
            $weight = $growth * self::MONTHS[(int) $local->format('n') - 1] * self::HOURS[(int) $local->format('G')] * $length;
            $this->weights[] = $weight;
            $this->cumulative[] = $this->cumulative[\count($this->cumulative) - 1] + $weight;
        }
        $this->total = $this->cumulative[\count($this->cumulative) - 1];
    }

    /**
     * The weight of a department in the choice of the products of an order placed at the moment: its season.
     */
    public static function departmentWeight(string $department, \DateTimeImmutable $at): float
    {
        $season = self::DEPARTMENTS[$department] ?? null;

        return null === $season ? 1.0 : $season[(int) $at->setTimezone(new \DateTimeZone(self::ZONE))->format('n') - 1];
    }

    /**
     * The moment the order of the index is placed, in whole seconds.
     */
    public function placedAt(int $index): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$this->secondOf($index));
    }

    /**
     * The first index placed at the moment or later (the number of orders placed before it).
     */
    public function indexAt(\DateTimeImmutable $moment): int
    {
        $at = $moment->getTimestamp();
        if ($at <= $this->start) {
            return 0;
        }
        if ($at > $this->end) {
            return $this->orders;
        }
        $bucket = min(\count($this->weights) - 1, intdiv($at - $this->start, self::BUCKET_SECONDS));
        $traffic = $this->cumulative[$bucket] + $this->weights[$bucket] * ($at - $this->start - $bucket * self::BUCKET_SECONDS) / self::BUCKET_SECONDS;
        $index = max(0, min($this->orders, (int) ceil($traffic * $this->orders / $this->total - 0.5)));
        // The rounding of floats may miss by one: settle on the exact definition.
        while ($index > 0 && $this->secondOf($index - 1) >= $at) {
            --$index;
        }
        while ($index < $this->orders && $this->secondOf($index) < $at) {
            ++$index;
        }

        return $index;
    }

    private function secondOf(int $index): int
    {
        $traffic = ($index + 0.5) * $this->total / $this->orders;
        $low = 0;
        $high = \count($this->weights) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($this->cumulative[$middle] <= $traffic) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }
        $offset = (int) floor(($traffic - $this->cumulative[$low]) / $this->weights[$low] * self::BUCKET_SECONDS);

        return min($this->end, $this->start + $low * self::BUCKET_SECONDS + max(0, $offset));
    }
}
