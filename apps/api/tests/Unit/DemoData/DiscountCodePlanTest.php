<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Identity\DemoUuid;
use App\DemoData\Pricing\DiscountCodePlan;
use App\DemoData\Pricing\DiscountCodeSpec;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use PHPUnit\Framework\TestCase;

final class DiscountCodePlanTest extends TestCase
{
    private const string NAMESPACE = '8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8';

    private static function anchor(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-17 00:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * @return list<DiscountCodeSpec>
     */
    private static function codes(?string $namespace = null): array
    {
        return new DiscountCodePlan($namespace ?? self::NAMESPACE, self::anchor())->codes();
    }

    public function testThereIsADozenOrSoOfCodes(): void
    {
        self::assertGreaterThanOrEqual(12, \count(self::codes()));
        self::assertLessThanOrEqual(16, \count(self::codes()));
    }

    public function testTheIdIsTheUuidV5OfTheNumberOfTheCodeFromOne(): void
    {
        foreach (self::codes() as $index => $code) {
            self::assertSame(DemoUuid::v5(self::NAMESPACE, 'code:'.($index + 1)), $code->id);
        }
    }

    public function testTheTextsAreValidUniqueAndNotTheOnesOfTheSeed(): void
    {
        $texts = array_map(static fn (DiscountCodeSpec $code): string => $code->code->toString(), self::codes());

        self::assertSame($texts, array_values(array_unique($texts)));
        self::assertSame([], array_intersect($texts, ['WITAJ10', 'MINUS50', 'LATO15']));
        foreach ($texts as $text) {
            self::assertSame($text, DiscountCodeValue::of($text)->toString());
        }
    }

    public function testEveryCodeHasEndedBeforeTheAnchorWithinTheThreeYearsOfOrders(): void
    {
        $starts = [];
        foreach (self::codes() as $code) {
            $ends = $code->period->endsAt();
            self::assertNotNull($ends, $code->code->toString());
            self::assertLessThanOrEqual(self::anchor()->modify('-1 day'), $ends);
            self::assertGreaterThanOrEqual(self::anchor()->modify('-1095 days'), $code->period->startsAt());
            self::assertLessThan($code->period->startsAt(), $code->createdAt);
            self::assertTrue($code->enabled);
            $starts[] = $code->period->startsAt()->getTimestamp();
        }
        $sorted = $starts;
        sort($sorted);
        self::assertSame($sorted, $starts, 'The codes go in the order of their start.');
        self::assertGreaterThan(self::anchor()->modify('-120 days')->getTimestamp(), max(array_map(static fn (DiscountCodeSpec $code): int => $code->period->endsAt()?->getTimestamp() ?? 0, self::codes())), 'A code is recent.');
    }

    public function testTheCodesAreOfBothKindsAndAnAmountCodeNeedsAMinimumAboveIt(): void
    {
        $kinds = [];
        foreach (self::codes() as $code) {
            $kinds[$code->value->kind()->value] = true;
            if (DiscountKind::Amount === $code->value->kind()) {
                self::assertNotNull($code->minimumOrder);
                self::assertGreaterThan($code->value->value(), $code->minimumOrder->gross());
            }
        }

        self::assertSame(['percent', 'amount'], array_keys($kinds));
    }

    public function testTheCodesDependOnTheSeedAndTheAnchorOnly(): void
    {
        self::assertEquals(self::codes(), self::codes());
        self::assertNotEquals(array_map(static fn (DiscountCodeSpec $code): string => $code->id, self::codes()), array_map(static fn (DiscountCodeSpec $code): string => $code->id, self::codes('b3a7d1f0-3c64-4e8b-9a52-1d7e0c5f6a49')));
    }
}
