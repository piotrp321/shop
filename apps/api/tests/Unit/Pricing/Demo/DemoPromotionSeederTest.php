<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Application\Command\PlanPromotion;
use App\Pricing\Application\Demo\DemoPromotionList;
use App\Pricing\Application\Demo\DemoPromotionSeeder;
use App\Tests\Support\InMemory\RecordingMessageBus;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryVariantsForPricing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DemoPromotionSeederTest extends TestCase
{
    private const string TENT_1 = '00000000-0000-4000-8000-000000000001';
    private const string TENT_2 = '00000000-0000-4000-8000-000000000002';
    private const string GEL = '00000000-0000-4000-8000-000000000003';
    private const string MAP = '00000000-0000-4000-8000-000000000004';
    private const array VARIANTS = [
        self::GEL => 'zel-energetyczny-pyrko-start-cytrynowy/standard',
        self::TENT_1 => 'namiot-turystyczny-norvane-biwak-2-zielony/standard',
        self::MAP => 'mapa-turystyczna-tarvik-press-gorce-skladana/standard',
        self::TENT_2 => 'namiot-turystyczny-norvane-biwak-2-pomaranczowy/standard',
    ];

    public function testPlansEveryPromotionThroughPlanPromotionOfTheSeedFromNowForItsDays(): void
    {
        $bus = new RecordingMessageBus();
        $seeder = $this->seeder($bus);

        $count = $seeder->seed($this->promotions());

        self::assertSame(2, $count);
        $commands = $bus->dispatchedOf(PlanPromotion::class);
        self::assertCount(2, $commands);
        [$tents, $gels] = $commands;
        self::assertSame(['Namioty', 20, [self::TENT_1, self::TENT_2]], [$tents->name, $tents->percentOff, $tents->variantIds]);
        self::assertSame(['Żele', 15, [self::GEL]], [$gels->name, $gels->percentOff, $gels->variantIds]);
        self::assertEquals(new \DateTimeImmutable('2026-12-10T12:00:00Z'), $tents->endsAt);
        self::assertEquals(new \DateTimeImmutable('2026-11-24T12:00:00Z'), $gels->endsAt);
        foreach ($commands as $command) {
            self::assertNull($command->startsAt, 'the promotion starts now');
            self::assertNull($command->actorId);
            self::assertNull($command->actorName);
        }
        self::assertTrue(Uuid::isValid($tents->id) && $tents->id !== $gels->id);
    }

    public function testAVariantBelongsToTheFirstPromotionThatMatchesIt(): void
    {
        $bus = new RecordingMessageBus();
        $list = new DemoPromotionList([
            ['name' => 'Biwak 2', 'percentOff' => 10, 'days' => 30, 'patterns' => ['namiot-*-zielony/*']],
            ['name' => 'Namioty', 'percentOff' => 20, 'days' => 30, 'patterns' => ['namiot-*']],
        ]);

        $seeder = $this->seeder($bus);

        self::assertSame([], $seeder->problems($list));
        $seeder->seed($list);
        [$green, $rest] = $bus->dispatchedOf(PlanPromotion::class);
        self::assertSame([self::TENT_1], $green->variantIds);
        self::assertSame([self::TENT_2], $rest->variantIds, 'a variant is in one promotion only: the periods must not overlap');
    }

    public function testListsThePromotionsWithoutAVariant(): void
    {
        $list = new DemoPromotionList([
            ['name' => 'Namioty', 'percentOff' => 20, 'days' => 30, 'patterns' => ['namiot-*']],
            ['name' => 'Kajaki', 'percentOff' => 10, 'days' => 30, 'patterns' => ['kajak-*']],
            ['name' => 'Wszystko po namiotach', 'percentOff' => 10, 'days' => 30, 'patterns' => ['namiot-*-zielony/*']],
        ]);

        $problems = $this->seeder(new RecordingMessageBus())->problems($list);

        self::assertCount(2, $problems);
        self::assertStringContainsString('Kajaki', $problems[0]);
        self::assertStringContainsString('Wszystko po namiotach', $problems[1], 'every variant is in an earlier promotion');
    }

    public function testListsAPromotionOfMoreVariantsThanAPromotionMayHave(): void
    {
        $variants = [];
        for ($i = 1; $i <= 1001; ++$i) {
            $variants[\sprintf('00000000-0000-4000-8000-%012d', $i)] = 'namiot-'.$i.'/standard';
        }
        $list = new DemoPromotionList([['name' => 'Namioty', 'percentOff' => 20, 'days' => 30, 'patterns' => ['namiot-*']]]);

        $problems = new DemoPromotionSeeder(new InMemoryVariantsForPricing($variants), new RecordingMessageBus(), new FixedPricingClock('2026-11-10T12:00:00Z'))->problems($list);

        self::assertCount(1, $problems);
        self::assertStringContainsString('1001', $problems[0]);
        self::assertStringContainsString('1000', $problems[0]);
    }

    public function testSeedStopsAtAPromotionWithoutAVariantBeforeAnyCommand(): void
    {
        $bus = new RecordingMessageBus();
        $list = new DemoPromotionList([
            ['name' => 'Namioty', 'percentOff' => 20, 'days' => 30, 'patterns' => ['namiot-*']],
            ['name' => 'Kajaki', 'percentOff' => 10, 'days' => 30, 'patterns' => ['kajak-*']],
        ]);

        try {
            $this->seeder($bus)->seed($list);
            self::fail('Expected a failure.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Kajaki', $e->getMessage());
        }
        self::assertSame([], $bus->dispatched);
    }

    private function seeder(RecordingMessageBus $bus): DemoPromotionSeeder
    {
        return new DemoPromotionSeeder(new InMemoryVariantsForPricing(self::VARIANTS), $bus, new FixedPricingClock('2026-11-10T12:00:00Z'));
    }

    private function promotions(): DemoPromotionList
    {
        return new DemoPromotionList([
            ['name' => 'Namioty', 'percentOff' => 20, 'days' => 30, 'patterns' => ['namiot-*']],
            ['name' => 'Żele', 'percentOff' => 15, 'days' => 14, 'patterns' => ['zel-energetyczny-*']],
        ]);
    }
}
