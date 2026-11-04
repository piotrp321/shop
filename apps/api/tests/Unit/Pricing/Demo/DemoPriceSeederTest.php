<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Application\Command\SetVariantPrice;
use App\Pricing\Application\Demo\DemoPriceList;
use App\Pricing\Application\Demo\DemoPriceSeeder;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Tests\Support\InMemory\RecordingMessageBus;
use App\Tests\Unit\Pricing\InMemoryVariantsForPricing;
use PHPUnit\Framework\TestCase;

final class DemoPriceSeederTest extends TestCase
{
    private const array VARIANTS = [
        '00000000-0000-4000-8000-000000000003' => 'mapa-turystyczna-tarvik-press-gorce-skladana/standard',
        '00000000-0000-4000-8000-000000000001' => 'namiot-turystyczny-norvane-biwak-2-zielony/standard',
        '00000000-0000-4000-8000-000000000002' => 'mapa-turystyczna-tarvik-press-tatry-laminowana/standard',
    ];

    public function testSetsTheSeedPriceOfEveryVariantThroughTheCommandBus(): void
    {
        $bus = new RecordingMessageBus();
        $seeder = new DemoPriceSeeder(new InMemoryVariantsForPricing(self::VARIANTS), $bus);

        $count = $seeder->seed($this->prices());

        self::assertSame(3, $count);
        $commands = $bus->dispatchedOf(SetVariantPrice::class);
        self::assertSame(
            [
                ['00000000-0000-4000-8000-000000000001', 89900],
                ['00000000-0000-4000-8000-000000000002', 3490],
                ['00000000-0000-4000-8000-000000000003', 2490],
            ],
            array_map(static fn (SetVariantPrice $command): array => [$command->variantId, $command->gross], $commands),
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
    public function testReadsTheVariantsInBatches(int $variantCount, array $expectedPages): void
    {
        $variants = [];
        for ($i = 1; $i <= $variantCount; ++$i) {
            $variants[\sprintf('00000000-0000-4000-8000-%012d', $i)] = 'mapa-turystyczna-'.$i.'/standard';
        }
        $catalog = new InMemoryVariantsForPricing($variants);
        $bus = new RecordingMessageBus();

        self::assertSame($variantCount, new DemoPriceSeeder($catalog, $bus)->seed($this->prices()));

        self::assertCount($variantCount, $bus->dispatched);
        self::assertCount($variantCount, array_unique(array_map(static fn (object $command): string => $command instanceof SetVariantPrice ? $command->variantId : '', $bus->dispatched)));
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
        $seeder = new DemoPriceSeeder(new InMemoryVariantsForPricing([
            ...self::VARIANTS,
            '00000000-0000-4000-8000-000000000004' => 'kajak-turystyczny-vilmar-rzeka-1-zielony/standard',
        ]), new RecordingMessageBus());

        self::assertSame(['kajak-turystyczny-vilmar-rzeka-1-zielony/standard'], $seeder->unpricedKeys($this->prices()));
    }

    public function testEveryVariantPricedGivesNoKeys(): void
    {
        $seeder = new DemoPriceSeeder(new InMemoryVariantsForPricing(self::VARIANTS), new RecordingMessageBus());

        self::assertSame([], $seeder->unpricedKeys($this->prices()));
    }

    public function testSeedStopsAtAVariantWithoutPattern(): void
    {
        $bus = new RecordingMessageBus();
        $seeder = new DemoPriceSeeder(new InMemoryVariantsForPricing([
            '00000000-0000-4000-8000-000000000001' => 'kajak-turystyczny-vilmar-rzeka-1-zielony/standard',
        ]), $bus);

        try {
            $seeder->seed($this->prices());
            self::fail('Expected a failure.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('kajak-turystyczny-vilmar-rzeka-1-zielony/standard', $e->getMessage());
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
