<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Stock\AvailabilityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvailabilityLevelTest extends TestCase
{
    /**
     * @return iterable<string, array{int, AvailabilityLevel}>
     */
    public static function boundaries(): iterable
    {
        yield '0' => [0, AvailabilityLevel::OutOfStock];
        yield '1' => [1, AvailabilityLevel::LowStock];
        yield '5' => [5, AvailabilityLevel::LowStock];
        yield '6' => [6, AvailabilityLevel::InStock];
        yield '1 000 000' => [1_000_000, AvailabilityLevel::InStock];
    }

    #[DataProvider('boundaries')]
    public function testLevelOfTheAvailableQuantity(int $available, AvailabilityLevel $expected): void
    {
        self::assertSame($expected, AvailabilityLevel::of($available));
    }

    public function testValues(): void
    {
        self::assertSame(
            [AvailabilityLevel::OutOfStock, AvailabilityLevel::LowStock, AvailabilityLevel::InStock],
            array_map(AvailabilityLevel::from(...), ['out_of_stock', 'low_stock', 'in_stock']),
        );
    }

    public function testNegativeAvailableQuantityIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AvailabilityLevel::of(-1);
    }
}
