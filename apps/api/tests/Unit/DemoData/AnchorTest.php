<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Time\Anchor;
use PHPUnit\Framework\TestCase;

final class AnchorTest extends TestCase
{
    public function testTheDefaultIsTheStartOfTheCurrentUtcDay(): void
    {
        $anchor = Anchor::resolve(null, new \DateTimeImmutable('2026-11-17 19:45:12.5', new \DateTimeZone('Europe/Warsaw')));

        self::assertSame('2026-11-17T00:00:00+00:00', $anchor->format(\DATE_ATOM));
    }

    public function testAnOptionOverridesTheClock(): void
    {
        $anchor = Anchor::resolve('2026-01-05T12:30:00Z', new \DateTimeImmutable('2030-01-01'));

        self::assertSame('2026-01-05T12:30:00+00:00', $anchor->format(\DATE_ATOM));
    }

    public function testADateWithoutATimeIsMidnightUtc(): void
    {
        $anchor = Anchor::resolve('2026-01-05', new \DateTimeImmutable('2030-01-01'));

        self::assertSame('2026-01-05T00:00:00+00:00', $anchor->format(\DATE_ATOM));
    }

    public function testAnOffsetIsConvertedToUtc(): void
    {
        $anchor = Anchor::resolve('2026-01-05T02:00:00+02:00', new \DateTimeImmutable('2030-01-01'));

        self::assertSame('2026-01-05T00:00:00+00:00', $anchor->format(\DATE_ATOM));
    }

    public function testAnInvalidValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Anchor::resolve('yesterday-ish', new \DateTimeImmutable('2030-01-01'));
    }
}
