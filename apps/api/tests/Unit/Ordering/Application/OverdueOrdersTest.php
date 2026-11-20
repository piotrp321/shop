<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\OverdueOrders;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryOverdueOrderIds;
use PHPUnit\Framework\TestCase;

/**
 * The candidates of ordering:expire-overdue (spec: docs/features/async-reliability/spec.md, W5): new orders whose
 * deadline is earlier than now minus the window of the webhooks (5 minutes) and the grace (a parameter), so the usual
 * delayed message comes first; read in batches by the keyset (placed_at, id) until an empty batch, with the boundary
 * fixed when the walk starts.
 */
final class OverdueOrdersTest extends TestCase
{
    private const int GRACE_SECONDS = 600;

    private InMemoryOverdueOrderIds $ids;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->ids = new InMemoryOverdueOrderIds();
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 13:00:00+00:00'));
    }

    public function testTheBoundaryIsNowLessTheWindowOfTheWebhooksAndTheGrace(): void
    {
        // Deadline 12:44:59, 15 min 1 s ago: overdue. Deadline 12:45:00, exactly at the boundary: not yet.
        $this->ids->add('0192f0c4-7c1a-7c4e-9a6e-000000000001', new \DateTimeImmutable('2026-11-05 12:14:59+00:00'));
        $this->ids->add('0192f0c4-7c1a-7c4e-9a6e-000000000002', new \DateTimeImmutable('2026-11-05 12:15:00+00:00'));

        $batches = iterator_to_array($this->overdue()->batches(100), false);

        self::assertSame([['0192f0c4-7c1a-7c4e-9a6e-000000000001']], $batches);
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:45:00+00:00'), $this->ids->calls[0][0]);
    }

    public function testTheBatchesFollowTheKeysetUntilAnEmptyOne(): void
    {
        $this->ids->add('0192f0c4-7c1a-7c4e-9a6e-000000000003', new \DateTimeImmutable('2026-11-05 11:00:00+00:00'));
        $this->ids->add('0192f0c4-7c1a-7c4e-9a6e-000000000001', new \DateTimeImmutable('2026-11-05 10:00:00+00:00'));
        $this->ids->add('0192f0c4-7c1a-7c4e-9a6e-000000000002', new \DateTimeImmutable('2026-11-05 10:00:00+00:00'));

        $batches = iterator_to_array($this->overdue()->batches(2), false);

        self::assertSame([
            ['0192f0c4-7c1a-7c4e-9a6e-000000000001', '0192f0c4-7c1a-7c4e-9a6e-000000000002'],
            ['0192f0c4-7c1a-7c4e-9a6e-000000000003'],
        ], $batches);
        self::assertCount(3, $this->ids->calls, 'the walk ends on an empty batch');
        self::assertNull($this->ids->calls[0][1]);
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-000000000002', $this->ids->calls[1][1]?->orderId);
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-000000000003', $this->ids->calls[2][1]?->orderId);
        self::assertSame([2, 2, 2], array_column($this->ids->calls, 2));
    }

    public function testTheBoundaryStaysWhileTheWalkGoesOn(): void
    {
        $this->ids->add('0192f0c4-7c1a-7c4e-9a6e-000000000001', new \DateTimeImmutable('2026-11-05 10:00:00+00:00'));
        $this->ids->add('0192f0c4-7c1a-7c4e-9a6e-000000000002', new \DateTimeImmutable('2026-11-05 10:01:00+00:00'));

        foreach ($this->overdue()->batches(1) as $batch) {
            $this->clock->now = $this->clock->now->modify('+1 hour');
        }

        self::assertCount(3, $this->ids->calls);
        foreach ($this->ids->calls as [$deadlineBefore]) {
            self::assertEquals(new \DateTimeImmutable('2026-11-05 12:45:00+00:00'), $deadlineBefore);
        }
    }

    public function testNoCandidatesIsNoBatch(): void
    {
        self::assertSame([], iterator_to_array($this->overdue()->batches(100), false));
        self::assertCount(1, $this->ids->calls);
    }

    private function overdue(): OverdueOrders
    {
        return new OverdueOrders($this->ids, $this->clock, self::GRACE_SECONDS);
    }
}
