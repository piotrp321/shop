<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Application;

use App\Cart\Application\Command\PurgeExpiredCarts;
use App\Cart\Application\Command\PurgeExpiredCartsHandler;
use App\Tests\Unit\Cart\FixedClock;
use App\Tests\Unit\Cart\InMemoryExpiredCarts;
use PHPUnit\Framework\TestCase;

final class PurgeExpiredCartsHandlerTest extends TestCase
{
    private InMemoryExpiredCarts $expired;
    private PurgeExpiredCartsHandler $handler;

    protected function setUp(): void
    {
        $this->expired = new InMemoryExpiredCarts();
        $this->handler = new PurgeExpiredCartsHandler($this->expired, new FixedClock(new \DateTimeImmutable('2026-11-03 12:00:00+00:00')));
    }

    public function testOneBatchRemovesTheEarliestExpiredCartsUpToTheBatchSize(): void
    {
        $this->expired->cart('a', '2026-11-01 00:00:00+00:00')->cart('b', '2026-10-01 00:00:00+00:00')->cart('c', '2026-10-15 00:00:00+00:00');

        self::assertSame(2, ($this->handler)(new PurgeExpiredCarts(2)));
        self::assertSame(['a'], $this->expired->ids());
        self::assertSame(1, ($this->handler)(new PurgeExpiredCarts(2)));
        self::assertSame(0, ($this->handler)(new PurgeExpiredCarts(2)));
    }

    public function testACartIsExpiredOnlyAfterItsExpiryTimeAccordingToTheClock(): void
    {
        $this->expired->cart('at-now', '2026-11-03 12:00:00+00:00')->cart('later', '2026-11-03 12:00:01+00:00')->cart('before', '2026-11-03 11:59:59+00:00');

        self::assertSame(1, ($this->handler)(new PurgeExpiredCarts(10)));
        self::assertEqualsCanonicalizing(['at-now', 'later'], $this->expired->ids());
    }

    public function testNothingExpiredRemovesNothing(): void
    {
        $this->expired->cart('a', '2026-12-01 00:00:00+00:00');

        self::assertSame(0, ($this->handler)(new PurgeExpiredCarts(1000)));
        self::assertSame(['a'], $this->expired->ids());
    }

    public function testABatchSizeBelowOneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PurgeExpiredCarts(0);
    }
}
