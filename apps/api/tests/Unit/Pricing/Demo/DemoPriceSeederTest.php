<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Application\Command\SetProductPrice;
use App\Pricing\Application\Demo\DemoPriceList;
use App\Pricing\Application\Demo\DemoPriceSeeder;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Tests\Support\InMemory\RecordingMessageBus;
use App\Tests\Unit\Pricing\InMemoryProductsForPricing;
use PHPUnit\Framework\TestCase;

final class DemoPriceSeederTest extends TestCase
{
    private const array PRODUCTS = [
        '00000000-0000-4000-8000-000000000003' => 'mapa-turystyczna-tarvik-press-gorce-skladana',
        '00000000-0000-4000-8000-000000000001' => 'namiot-turystyczny-norvane-biwak-2-zielony',
        '00000000-0000-4000-8000-000000000002' => 'mapa-turystyczna-tarvik-press-tatry-laminowana',
    ];

    public function testSetsTheSeedPriceOfEveryProductThroughTheCommandBus(): void
    {
        $bus = new RecordingMessageBus();
        $seeder = new DemoPriceSeeder(new InMemoryProductsForPricing(self::PRODUCTS), $bus);

        $count = $seeder->seed($this->prices());

        self::assertSame(3, $count);
        $commands = $bus->dispatchedOf(SetProductPrice::class);
        self::assertSame(
            [
                ['00000000-0000-4000-8000-000000000001', 89900],
                ['00000000-0000-4000-8000-000000000002', 3490],
                ['00000000-0000-4000-8000-000000000003', 2490],
            ],
            array_map(static fn (SetProductPrice $command): array => [$command->productId, $command->gross], $commands),
        );
        foreach ($commands as $command) {
            self::assertSame(PriceChangeSource::Seed, $command->source);
            self::assertNull($command->expectedVersion);
            self::assertNull($command->actorId);
        }
    }

    /**
     * A short batch is the last one; a full one is followed by another read.
     *
     * @param list<int> $expectedPages
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('catalogSizes')]
    public function testReadsTheProductsInBatches(int $productCount, array $expectedPages): void
    {
        $products = [];
        for ($i = 1; $i <= $productCount; ++$i) {
            $products[\sprintf('00000000-0000-4000-8000-%012d', $i)] = 'mapa-turystyczna-'.$i;
        }
        $catalog = new InMemoryProductsForPricing($products);
        $bus = new RecordingMessageBus();

        self::assertSame($productCount, new DemoPriceSeeder($catalog, $bus)->seed($this->prices()));

        self::assertCount($productCount, $bus->dispatched);
        self::assertCount($productCount, array_unique(array_map(static fn (object $command): string => $command instanceof SetProductPrice ? $command->productId : '', $bus->dispatched)));
        self::assertSame($expectedPages, $catalog->pageLimits);
    }

    /** @return iterable<string, array{int, list<int>}> */
    public static function catalogSizes(): iterable
    {
        yield 'empty' => [0, [500]];
        yield 'two and a half batches' => [1201, [500, 500, 500]];
        yield 'exactly two batches' => [1000, [500, 500, 500]];
    }

    public function testListsTheSlugsWithoutPattern(): void
    {
        $seeder = new DemoPriceSeeder(new InMemoryProductsForPricing([
            ...self::PRODUCTS,
            '00000000-0000-4000-8000-000000000004' => 'kajak-turystyczny-vilmar-rzeka-1-zielony',
        ]), new RecordingMessageBus());

        self::assertSame(['kajak-turystyczny-vilmar-rzeka-1-zielony'], $seeder->unpricedSlugs($this->prices()));
    }

    public function testEveryProductPricedGivesNoSlugs(): void
    {
        $seeder = new DemoPriceSeeder(new InMemoryProductsForPricing(self::PRODUCTS), new RecordingMessageBus());

        self::assertSame([], $seeder->unpricedSlugs($this->prices()));
    }

    public function testSeedStopsAtAProductWithoutPattern(): void
    {
        $bus = new RecordingMessageBus();
        $seeder = new DemoPriceSeeder(new InMemoryProductsForPricing([
            '00000000-0000-4000-8000-000000000001' => 'kajak-turystyczny-vilmar-rzeka-1-zielony',
        ]), $bus);

        try {
            $seeder->seed($this->prices());
            self::fail('Expected a failure.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('kajak-turystyczny-vilmar-rzeka-1-zielony', $e->getMessage());
        }
        self::assertSame([], $bus->dispatched);
    }

    private function prices(): DemoPriceList
    {
        return new DemoPriceList([
            ['pattern' => 'mapa-turystyczna-*-tatry-*', 'gross' => 3490],
            ['pattern' => 'mapa-turystyczna-*', 'gross' => 2490],
            ['pattern' => 'namiot-*', 'gross' => 89900],
        ]);
    }
}
