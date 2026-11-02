<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Stock\StockChangeOrigin;
use App\Inventory\Domain\Stock\StockChangeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockChangeOriginTest extends TestCase
{
    public function testStaffNamesTheActor(): void
    {
        $origin = StockChangeOrigin::staff('sub-1', 'Wanda Magazyn');

        self::assertSame(StockChangeSource::Staff, $origin->source());
        self::assertSame('sub-1', $origin->actorId());
        self::assertSame('Wanda Magazyn', $origin->actorName());
    }

    public function testSeedAndSystemHaveNoActor(): void
    {
        foreach ([StockChangeOrigin::seed(), StockChangeOrigin::system()] as $origin) {
            self::assertNull($origin->actorId());
            self::assertNull($origin->actorName());
        }
        self::assertSame(StockChangeSource::Seed, StockChangeOrigin::seed()->source());
        self::assertSame(StockChangeSource::System, StockChangeOrigin::system()->source());
        self::assertSame(
            [StockChangeSource::Staff, StockChangeSource::Seed, StockChangeSource::System],
            array_map(StockChangeSource::from(...), ['staff', 'seed', 'system']),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidActors(): iterable
    {
        yield 'empty id' => ['', 'Wanda Magazyn'];
        yield 'id over 255 bytes' => [str_repeat('s', 256), 'Wanda Magazyn'];
        yield 'blank name' => ['sub-1', '  '];
        yield 'name over 255 characters' => ['sub-1', str_repeat('ż', 256)];
    }

    #[DataProvider('invalidActors')]
    public function testStaffNeedsAnActor(string $actorId, string $actorName): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StockChangeOrigin::staff($actorId, $actorName);
    }
}
