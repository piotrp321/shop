<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Demo;

use App\Inventory\Application\Command\CreateEmptyStock;
use App\Inventory\Application\Command\ReceiveStock;
use App\Inventory\Application\Demo\DemoStockList;
use App\Inventory\Application\Demo\DemoStockSeeder;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Tests\Support\InMemory\RecordingMessageBus;
use App\Tests\Unit\Inventory\InMemoryVariantsForInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DemoStockSeederTest extends TestCase
{
    private const array VARIANTS = [
        '00000000-0000-4000-8000-000000000003' => 'mapa-turystyczna-tarvik-press-gorce/skladana',
        '00000000-0000-4000-8000-000000000001' => 'namiot-turystyczny-norvane-biwak-2/zielony',
        '00000000-0000-4000-8000-000000000002' => 'mapa-turystyczna-tarvik-press-tatry/laminowana',
    ];

    public function testReceivesTheQuantityOfEveryVariantAsTheSeedAndCreatesAnEmptyStockForZero(): void
    {
        $bus = new RecordingMessageBus();
        $seeder = new DemoStockSeeder(new InMemoryVariantsForInventory(self::VARIANTS), $bus);

        $count = $seeder->seed($this->stock());

        self::assertSame(3, $count);
        self::assertCount(3, $bus->dispatched);
        $first = $bus->dispatched[0];
        self::assertInstanceOf(ReceiveStock::class, $first);
        self::assertSame(['00000000-0000-4000-8000-000000000001', 40, 'seed:00000000-0000-4000-8000-000000000001', StockChangeSource::Seed, null, null, null], [
            $first->variantId, $first->quantity, $first->receiptKey, $first->source, $first->note, $first->actorId, $first->actorName,
        ]);
        $second = $bus->dispatched[1];
        self::assertInstanceOf(CreateEmptyStock::class, $second);
        self::assertSame('00000000-0000-4000-8000-000000000002', $second->variantId);
        $third = $bus->dispatched[2];
        self::assertInstanceOf(ReceiveStock::class, $third);
        self::assertSame(['00000000-0000-4000-8000-000000000003', 25], [$third->variantId, $third->quantity]);
    }

    /**
     * A short batch is the last one; a full one is followed by another read.
     *
     * @param list<int> $expectedPages
     */
    #[DataProvider('catalogSizes')]
    public function testReadsTheVariantsInBatches(int $variantCount, array $expectedPages): void
    {
        $variants = [];
        for ($i = 1; $i <= $variantCount; ++$i) {
            $variants[\sprintf('00000000-0000-4000-8000-%012d', $i)] = 'mapa-turystyczna-'.$i.'/standard';
        }
        $catalog = new InMemoryVariantsForInventory($variants);
        $bus = new RecordingMessageBus();

        self::assertSame($variantCount, new DemoStockSeeder($catalog, $bus)->seed($this->stock()));

        self::assertCount($variantCount, $bus->dispatched);
        self::assertSame($expectedPages, $catalog->pageLimits);
    }

    /** @return iterable<string, array{int, list<int>}> */
    public static function catalogSizes(): iterable
    {
        yield 'empty' => [0, [500]];
        yield 'two and a half batches' => [1201, [500, 500, 500]];
        yield 'exactly two batches' => [1000, [500, 500, 500]];
    }

    public function testListsTheKeysWithoutPattern(): void
    {
        $seeder = new DemoStockSeeder(new InMemoryVariantsForInventory([
            ...self::VARIANTS,
            '00000000-0000-4000-8000-000000000004' => 'kajak-turystyczny-vilmar-rzeka-1/zielony',
        ]), new RecordingMessageBus());

        self::assertSame(['kajak-turystyczny-vilmar-rzeka-1/zielony'], $seeder->unmatchedKeys($this->stock()));
    }

    public function testEveryVariantMatchedGivesNoKeys(): void
    {
        $seeder = new DemoStockSeeder(new InMemoryVariantsForInventory(self::VARIANTS), new RecordingMessageBus());

        self::assertSame([], $seeder->unmatchedKeys($this->stock()));
    }

    public function testSeedStopsAtAVariantWithoutPattern(): void
    {
        $bus = new RecordingMessageBus();
        $seeder = new DemoStockSeeder(new InMemoryVariantsForInventory([
            '00000000-0000-4000-8000-000000000001' => 'kajak-turystyczny-vilmar-rzeka-1/zielony',
        ]), $bus);

        try {
            $seeder->seed($this->stock());
            self::fail('Expected a failure.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('kajak-turystyczny-vilmar-rzeka-1/zielony', $e->getMessage());
        }
        self::assertSame([], $bus->dispatched);
    }

    private function stock(): DemoStockList
    {
        return new DemoStockList([
            ['pattern' => 'mapa-turystyczna-*-tatry/*', 'quantity' => 0],
            ['pattern' => 'mapa-turystyczna-*', 'quantity' => 25],
            ['pattern' => 'namiot-*', 'quantity' => 40],
        ]);
    }
}
