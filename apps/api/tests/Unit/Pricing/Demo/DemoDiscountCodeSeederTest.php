<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Application\Command\CreateDiscountCode;
use App\Pricing\Application\Demo\DemoDiscountCodeList;
use App\Pricing\Application\Demo\DemoDiscountCodeSeeder;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Tests\Support\InMemory\RecordingMessageBus;
use App\Tests\Unit\Pricing\FixedPricingClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DemoDiscountCodeSeederTest extends TestCase
{
    public function testCreatesEveryCodeThroughCreateDiscountCodeWithoutAnActorWithTimesRelativeToNow(): void
    {
        $bus = new RecordingMessageBus();
        $seeder = new DemoDiscountCodeSeeder($bus, new FixedPricingClock('2026-11-10T12:00:00Z'));

        $count = $seeder->seed(new DemoDiscountCodeList([
            ['code' => 'WITAJ10', 'kind' => DiscountKind::Percent, 'value' => 10, 'minimumOrderGross' => null, 'startsInDays' => 0, 'endsInDays' => null],
            ['code' => 'MINUS50', 'kind' => DiscountKind::Amount, 'value' => 5000, 'minimumOrderGross' => 30000, 'startsInDays' => 0, 'endsInDays' => null],
            ['code' => 'LATO15', 'kind' => DiscountKind::Percent, 'value' => 15, 'minimumOrderGross' => null, 'startsInDays' => -60, 'endsInDays' => -30],
        ]));

        self::assertSame(3, $count);
        $commands = $bus->dispatchedOf(CreateDiscountCode::class);
        self::assertCount(3, $commands);
        [$welcome, $minus, $summer] = $commands;
        self::assertSame(['WITAJ10', DiscountKind::Percent, 10, null, null, true], [$welcome->code, $welcome->kind, $welcome->value, $welcome->minimumOrderGross, $welcome->usageLimit, $welcome->enabled]);
        self::assertSame(['MINUS50', DiscountKind::Amount, 5000, 30000], [$minus->code, $minus->kind, $minus->value, $minus->minimumOrderGross]);
        self::assertEquals(new \DateTimeImmutable('2026-11-10T12:00:00Z'), $welcome->startsAt);
        self::assertNull($welcome->endsAt);
        self::assertEquals(new \DateTimeImmutable('2026-09-11T12:00:00Z'), $summer->startsAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-11T12:00:00Z'), $summer->endsAt);
        foreach ($commands as $command) {
            self::assertNull($command->actorId);
            self::assertNull($command->actorName);
            self::assertTrue(Uuid::isValid($command->id));
        }
        self::assertCount(3, array_unique(array_map(static fn (CreateDiscountCode $command): string => $command->id, $commands)));
    }
}
