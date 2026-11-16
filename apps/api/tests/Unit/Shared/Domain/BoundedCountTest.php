<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\BoundedCount;
use PHPUnit\Framework\TestCase;

final class BoundedCountTest extends TestCase
{
    public function testFewerRowsThanTheLimitAreAnExactCount(): void
    {
        $count = BoundedCount::ofRowsRead(7, 10);

        self::assertSame(7, $count->value);
        self::assertFalse($count->capped);
    }

    public function testExactlyTheLimitIsStillAnExactCount(): void
    {
        $count = BoundedCount::ofRowsRead(10, 10);

        self::assertSame(10, $count->value);
        self::assertFalse($count->capped);
    }

    public function testOneRowMoreThanTheLimitIsACappedCountAtTheLimit(): void
    {
        $count = BoundedCount::ofRowsRead(11, 10);

        self::assertSame(10, $count->value);
        self::assertTrue($count->capped);
    }

    public function testItSerializesToTheTwoKeysOfTheContract(): void
    {
        self::assertSame('{"value":10000,"capped":true}', json_encode(BoundedCount::ofRowsRead(10001, 10000), \JSON_THROW_ON_ERROR));
    }

    public function testMoreRowsThanTheLimitPlusOneCannotHaveBeenRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BoundedCount::ofRowsRead(12, 10);
    }

    public function testNegativeRowsAndANonPositiveLimitAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BoundedCount::ofRowsRead(-1, 10);
    }
}
