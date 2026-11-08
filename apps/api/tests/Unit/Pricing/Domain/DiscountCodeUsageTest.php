<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageId;
use App\Pricing\Domain\DiscountCode\OrderId;
use PHPUnit\Framework\TestCase;

/**
 * A usage of a discount code by an order (A1): its own aggregate, active until it is released.
 */
final class DiscountCodeUsageTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6b00';
    private const string CODE = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6b01';
    private const string ORDER = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6b02';

    public function testARecordedUsageIsActive(): void
    {
        $usedAt = new \DateTimeImmutable('2026-11-10T12:00:00.400000Z');

        $usage = DiscountCodeUsage::record(DiscountCodeUsageId::fromString(self::ID), DiscountCodeId::fromString(self::CODE), OrderId::fromString(self::ORDER), $usedAt);

        self::assertSame(self::ID, $usage->id()->toString());
        self::assertSame(self::CODE, $usage->codeId()->toString());
        self::assertSame(self::ORDER, $usage->orderId()->toString());
        self::assertEquals($usedAt, $usage->usedAt());
        self::assertNull($usage->releasedAt());
        self::assertTrue($usage->isActive());
    }

    public function testAReleasedUsageIsNotActive(): void
    {
        $usage = DiscountCodeUsage::reconstitute(
            DiscountCodeUsageId::fromString(self::ID),
            DiscountCodeId::fromString(self::CODE),
            OrderId::fromString(self::ORDER),
            new \DateTimeImmutable('2026-11-10T12:00:00Z'),
            new \DateTimeImmutable('2026-11-10T12:35:00Z'),
        );

        self::assertFalse($usage->isActive());
        self::assertSame('2026-11-10T12:35:00+00:00', $usage->releasedAt()?->format(\DATE_ATOM));
    }

    public function testIdsAreUuidsInLowerCase(): void
    {
        self::assertSame(self::ORDER, OrderId::fromString(strtoupper(self::ORDER))->toString());
        self::assertTrue(DiscountCodeId::fromString(self::CODE)->equals(DiscountCodeId::fromString(strtoupper(self::CODE))));

        $this->expectException(\InvalidArgumentException::class);
        OrderId::fromString('order-1');
    }
}
