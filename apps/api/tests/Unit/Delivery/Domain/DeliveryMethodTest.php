<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery\Domain;

use App\Delivery\Domain\DeliveryMethod;
use App\Delivery\Domain\DeliveryMethodDescription;
use App\Delivery\Domain\DeliveryMethodId;
use App\Delivery\Domain\DeliveryMethodLimitReached;
use App\Delivery\Domain\DeliveryMethodName;
use App\Delivery\Domain\DeliveryPosition;
use App\Delivery\Domain\DeliveryPrice;
use App\Delivery\Domain\FreeDeliveryThreshold;
use App\Delivery\Domain\TrackingUrlTemplate;
use App\Tests\Unit\Delivery\DeliveryMethods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The aggregate DeliveryMethod: at most 20 active methods (P16), the cost for the value of the goods (W2), its
 * version and moments.
 */
final class DeliveryMethodTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c8a01';

    public function testANewMethodHasItsValuesAndVersionOne(): void
    {
        $now = new \DateTimeImmutable('2026-11-20T12:00:00+00:00');

        $method = $this->create(active: true, activeMethods: 19, now: $now);

        self::assertSame(self::ID, $method->id()->toString());
        self::assertSame('Kurier standardowy', $method->name()->toString());
        self::assertSame('Dostawa w 2–3 dni robocze', $method->description()->toString());
        self::assertSame(1999, $method->price()->gross());
        self::assertSame(29_900, $method->freeFrom()?->gross());
        self::assertTrue($method->isActive());
        self::assertSame(10, $method->position()->value());
        self::assertSame('https://tracking.example.com/{numer}', $method->trackingUrlTemplate()?->toString());
        self::assertSame(1, $method->version());
        self::assertEquals($now, $method->createdAt());
        self::assertEquals($now, $method->updatedAt());
        self::assertSame(20, DeliveryMethod::MAX_ACTIVE);
    }

    public function testATwentyFirstActiveMethodIsNotCreated(): void
    {
        $this->expectException(DeliveryMethodLimitReached::class);
        $this->expectExceptionMessage('Aktywnych metod dostawy może być najwyżej 20. Wyłącz inną metodę.');

        $this->create(active: true, activeMethods: 20);
    }

    public function testAnInactiveMethodIsCreatedWhateverTheNumberOfActiveOnes(): void
    {
        self::assertFalse($this->create(active: false, activeMethods: 20)->isActive());
        self::assertFalse($this->create(active: false, activeMethods: 25)->isActive());
    }

    public function testAReviseChangesEverythingAndRaisesTheVersion(): void
    {
        $method = DeliveryMethods::stored(id: self::ID);
        $now = new \DateTimeImmutable('2026-11-21T08:00:00+00:00');

        $method->revise(
            DeliveryMethodName::of('Kurier ekspresowy'),
            DeliveryMethodDescription::of('Jutro'),
            DeliveryPrice::of(3999),
            FreeDeliveryThreshold::of(99_900),
            false,
            DeliveryPosition::of(20),
            TrackingUrlTemplate::of('https://example.com/{numer}'),
            20,
            $now,
        );

        self::assertSame(
            ['Kurier ekspresowy', 'Jutro', 3999, 99_900, false, 20, 'https://example.com/{numer}', 2],
            [$method->name()->toString(), $method->description()->toString(), $method->price()->gross(), $method->freeFrom()?->gross(), $method->isActive(), $method->position()->value(), $method->trackingUrlTemplate()?->toString(), $method->version()],
        );
        self::assertEquals(new \DateTimeImmutable(DeliveryMethods::CREATED_AT), $method->createdAt());
        self::assertEquals($now, $method->updatedAt());
    }

    public function testAReviseRemovesTheThresholdAndTheTemplate(): void
    {
        $method = DeliveryMethods::stored(freeFrom: 29_900, template: 'https://example.com/{numer}');

        $this->revise($method, active: true, activeMethods: 1, freeFrom: null, template: null);

        self::assertNull($method->freeFrom());
        self::assertNull($method->trackingUrlTemplate());
    }

    public function testAnInactiveMethodIsNotActivatedAsTheTwentyFirst(): void
    {
        $method = DeliveryMethods::stored(active: false);

        try {
            $this->revise($method, active: true, activeMethods: 20);
            self::fail('The activation must be refused.');
        } catch (DeliveryMethodLimitReached) {
            self::assertFalse($method->isActive());
            self::assertSame(1, $method->version());
        }
    }

    public function testAnInactiveMethodIsActivatedBelowTheLimit(): void
    {
        $method = DeliveryMethods::stored(active: false);

        $this->revise($method, active: true, activeMethods: 19);

        self::assertTrue($method->isActive());
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function changesWithoutAnActivation(): iterable
    {
        yield 'an active method stays active' => [true, true];
        yield 'an active method is disabled' => [true, false];
        yield 'an inactive method stays inactive' => [false, false];
    }

    #[DataProvider('changesWithoutAnActivation')]
    public function testAChangeThatActivatesNothingIgnoresTheLimit(bool $wasActive, bool $active): void
    {
        $method = DeliveryMethods::stored(active: $wasActive);

        $this->revise($method, active: $active, activeMethods: 25);

        self::assertSame($active, $method->isActive());
        self::assertSame(2, $method->version());
    }

    /**
     * @return iterable<string, array{int, ?int, int, int, ?int}>
     */
    public static function costs(): iterable
    {
        yield 'no threshold' => [1999, null, 1_000_000, 1999, null];
        yield 'below the threshold' => [1999, 29_900, 24_999, 1999, 4901];
        yield 'one grosz below the threshold' => [1999, 29_900, 29_899, 1999, 1];
        yield 'at the threshold' => [1999, 29_900, 29_900, 0, null];
        yield 'above the threshold' => [1999, 29_900, 50_000, 0, null];
        yield 'a free method below its threshold' => [0, 29_900, 100, 0, null];
        yield 'a free method without a threshold' => [0, null, 0, 0, null];
        yield 'no goods' => [1999, 29_900, 0, 1999, 29_900];
    }

    #[DataProvider('costs')]
    public function testTheCostIsZeroFromTheThresholdAndTheMissingAmountOnlyWhenTheCostIsNot(int $price, ?int $freeFrom, int $goods, int $cost, ?int $missing): void
    {
        $method = DeliveryMethods::stored(price: $price, freeFrom: $freeFrom);

        self::assertSame($cost, $method->costFor($goods));
        self::assertSame($missing, $method->missingToFreeFor($goods));
    }

    public function testNegativeGoodsAreALogicError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeliveryMethods::stored()->costFor(-1);
    }

    private function create(bool $active, int $activeMethods, ?\DateTimeImmutable $now = null): DeliveryMethod
    {
        return DeliveryMethod::create(
            DeliveryMethodId::fromString(self::ID),
            DeliveryMethodName::of('Kurier standardowy'),
            DeliveryMethodDescription::of('Dostawa w 2–3 dni robocze'),
            DeliveryPrice::of(1999),
            FreeDeliveryThreshold::of(29_900),
            $active,
            DeliveryPosition::of(10),
            TrackingUrlTemplate::of('https://tracking.example.com/{numer}'),
            $activeMethods,
            $now ?? new \DateTimeImmutable(),
        );
    }

    private function revise(DeliveryMethod $method, bool $active, int $activeMethods, ?int $freeFrom = 29_900, ?string $template = 'https://example.com/{numer}'): void
    {
        $method->revise(
            $method->name(),
            $method->description(),
            $method->price(),
            FreeDeliveryThreshold::optional($freeFrom),
            $active,
            $method->position(),
            TrackingUrlTemplate::optional($template),
            $activeMethods,
            new \DateTimeImmutable(),
        );
    }
}
