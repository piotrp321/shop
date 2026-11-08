<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\InvalidPromotion;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionActor;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionNotEditable;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use App\Pricing\Domain\Promotion\PromotionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromotionTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a00';
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a01';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a02';
    private const string C = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a03';
    /** The moment of the calls, with a fraction of a second. */
    private const string NOW = '2026-11-10T12:00:00.400000Z';

    public function testStatusFollowsTheTimeAroundThePeriod(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        self::assertSame(PromotionStatus::Planned, $promotion->status(new \DateTimeImmutable('2026-11-10T23:59:59.999999Z')));
        self::assertSame(PromotionStatus::Active, $promotion->status(new \DateTimeImmutable('2026-11-11T00:00:00Z')));
        self::assertSame(PromotionStatus::Active, $promotion->status(new \DateTimeImmutable('2026-11-20T23:59:59.999999Z')));
        self::assertSame(PromotionStatus::Ended, $promotion->status(new \DateTimeImmutable('2026-11-21T00:00:00Z')));
    }

    public function testPlanKeepsAFutureStartAndRecordsTheActor(): void
    {
        $actor = PromotionActor::staff('sub-1', 'Anna Marketing');
        $promotion = Promotion::plan(
            PromotionId::fromString(self::ID),
            '  Jesień w górach ',
            PercentOff::of(20),
            new \DateTimeImmutable('2026-11-11T00:00:00+01:00'),
            new \DateTimeImmutable('2026-11-21T00:00:00+01:00'),
            self::variants(self::A, self::B),
            $actor,
            self::now(),
        );

        self::assertSame(self::ID, $promotion->id()->toString());
        self::assertSame('Jesień w górach', $promotion->name());
        self::assertSame(20, $promotion->percentOff()->value());
        self::assertSame('2026-11-10T23:00:00+00:00', $promotion->period()->startsAt()->format(\DATE_ATOM));
        self::assertSame('2026-11-20T23:00:00+00:00', $promotion->period()->endsAt()->format(\DATE_ATOM));
        self::assertSame([self::A, self::B], self::ids($promotion->variantIds()));
        self::assertSame($actor, $promotion->createdBy());
        self::assertSame($actor, $promotion->updatedBy());
        self::assertEquals(self::now(), $promotion->createdAt());
        self::assertEquals(self::now(), $promotion->updatedAt());
        self::assertSame(1, $promotion->version());
        self::assertSame(PromotionStatus::Planned, $promotion->status(self::now()));
    }

    public function testTheStartOfNowIsTheNextWholeSecond(): void
    {
        self::assertSame('2026-11-10T12:00:01.000000+00:00', Promotion::startOfNow(self::now())->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2026-11-10T12:00:01.000000+00:00', Promotion::startOfNow(new \DateTimeImmutable('2026-11-10T13:00:00+01:00'))->format('Y-m-d\TH:i:s.uP'));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function startsNowOrBefore(): iterable
    {
        yield 'no start' => [null];
        yield 'now' => [self::NOW];
        yield 'in the second of now, after it' => ['2026-11-10T12:00:00.900000Z'];
        yield 'an hour ago' => ['2026-11-10T11:00:00Z'];
        yield 'a year ago' => ['2025-11-10T11:00:00Z'];
    }

    #[DataProvider('startsNowOrBefore')]
    public function testPlanMovesAStartAtOrBeforeNowToTheStartOfNow(?string $startsAt): void
    {
        $promotion = Promotion::plan(
            PromotionId::fromString(self::ID),
            'Od teraz',
            PercentOff::of(10),
            null === $startsAt ? null : new \DateTimeImmutable($startsAt),
            new \DateTimeImmutable('2026-12-01T00:00:00Z'),
            self::variants(self::A),
            null,
            self::now(),
        );

        self::assertSame('2026-11-10T12:00:01+00:00', $promotion->period()->startsAt()->format(\DATE_ATOM));
        self::assertSame(PromotionStatus::Planned, $promotion->status(self::now()));
        self::assertSame(PromotionStatus::Active, $promotion->status(new \DateTimeImmutable('2026-11-10T12:00:01Z')));
        self::assertNull($promotion->createdBy());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endsNowOrBefore(): iterable
    {
        yield 'now' => [self::NOW];
        yield 'in the second of now' => ['2026-11-10T12:00:00.900000Z'];
        yield 'yesterday' => ['2026-11-09T12:00:00Z'];
    }

    #[DataProvider('endsNowOrBefore')]
    public function testPlanRefusesAnEndAtOrBeforeNow(string $endsAt): void
    {
        $this->expectInvalid(InvalidPromotion::ENDS_AT, static fn () => Promotion::plan(
            PromotionId::fromString(self::ID), 'Za późno', PercentOff::of(10), null, new \DateTimeImmutable($endsAt), self::variants(self::A), null, self::now(),
        ));
    }

    public function testPlanRefusesAnEndThatLeavesNoSecondAfterTheStartOfNow(): void
    {
        // The end is in the future, but not after the start of now.
        $this->expectInvalid(InvalidPromotion::ENDS_AT, static fn () => Promotion::plan(
            PromotionId::fromString(self::ID), 'Za krótko', PercentOff::of(10), null, new \DateTimeImmutable('2026-11-10T12:00:01Z'), self::variants(self::A), null, self::now(),
        ));
    }

    public function testAPromotionLastsAtMost90Days(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2027-02-09T00:00:00Z');
        self::assertSame('2027-02-09T00:00:00+00:00', $promotion->period()->endsAt()->format(\DATE_ATOM));

        $this->expectInvalid(InvalidPromotion::ENDS_AT, fn () => $this->planned('2026-11-11T00:00:00Z', '2027-02-09T00:00:01Z'));
    }

    public function testNinetyDaysCountFromTheStartOfNowForAPromotionFromNow(): void
    {
        $promotion = Promotion::plan(
            PromotionId::fromString(self::ID), 'Długa', PercentOff::of(10), new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            new \DateTimeImmutable('2027-02-08T12:00:01Z'), self::variants(self::A), null, self::now(),
        );

        self::assertSame('2026-11-10T12:00:01+00:00', $promotion->period()->startsAt()->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ['   '];
        yield '101 characters' => [str_repeat('ą', 101)];
        yield 'a new line' => ["Jesień\nw górach"];
        yield 'a tab' => ["Jesień\tw górach"];
        yield 'a NUL inside' => ["Jesień\0w górach"];
        yield 'invalid UTF-8' => ["Jesie\xC5"];
        yield 'only no-break spaces' => ["\u{A0}\u{A0}"];
        yield 'only an em space' => ["\u{2003}"];
        yield 'only an ideographic space' => ["\u{3000}"];
        yield 'only a zero width space' => ["\u{200B}"];
        yield 'a mix of Unicode spaces' => ["\u{A0}\u{3000}\u{200B} \u{2003}"];
        yield 'a line separator inside' => ["a\u{2028}b"];
        yield 'a paragraph separator inside' => ["a\u{2029}b"];
        yield '101 characters between no-break spaces' => ["\u{A0}".str_repeat('ą', 101)."\u{A0}"];
    }

    #[DataProvider('invalidNames')]
    public function testTheNameHas1To100CharactersWithoutControlCharacters(string $name): void
    {
        $this->expectInvalid(InvalidPromotion::NAME, static fn () => Promotion::plan(
            PromotionId::fromString(self::ID), $name, PercentOff::of(10), null, new \DateTimeImmutable('2026-12-01T00:00:00Z'), self::variants(self::A), null, self::now(),
        ));
    }

    public function testTheNameIsTrimmedAtBothEndsOfUnicodeSpacesAndInvisibleFormatCharacters(): void
    {
        $promotion = Promotion::plan(
            PromotionId::fromString(self::ID), "\u{A0}\u{3000}\u{200B} Promo \u{2003}\u{FEFF}\u{A0}", PercentOff::of(10), null, new \DateTimeImmutable('2026-12-01T00:00:00Z'), self::variants(self::A), null, self::now(),
        );

        self::assertSame('Promo', $promotion->name());
    }

    public function testANameKeepsFormatCharactersAndSpacesInside(): void
    {
        $name = "Rodzina \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{A0}zimą";
        $promotion = Promotion::plan(
            PromotionId::fromString(self::ID), $name, PercentOff::of(10), null, new \DateTimeImmutable('2026-12-01T00:00:00Z'), self::variants(self::A), null, self::now(),
        );

        self::assertSame($name, $promotion->name());
    }

    #[DataProvider('invalidNames')]
    public function testReviseRefusesTheSameInvalidNames(string $name): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        $this->expectInvalid(InvalidPromotion::NAME, static fn () => $promotion->revise($name, PercentOff::of(10), null, new \DateTimeImmutable('2026-11-21T00:00:00Z'), self::variants(self::A), null, self::now()));
    }

    public function testReviseTrimsTheNameOfUnicodeSpaces(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        $promotion->revise("\u{A0}Nowa\u{3000}", PercentOff::of(10), null, new \DateTimeImmutable('2026-11-21T00:00:00Z'), self::variants(self::A), null, self::now());

        self::assertSame('Nowa', $promotion->name());
    }

    public function testANameOf100CharactersIsAccepted(): void
    {
        $promotion = Promotion::plan(
            PromotionId::fromString(self::ID), str_repeat('ą', 100), PercentOff::of(10), null, new \DateTimeImmutable('2026-12-01T00:00:00Z'), self::variants(self::A), null, self::now(),
        );

        self::assertSame(100, mb_strlen($promotion->name()));
    }

    public function testAPromotionHas1To1000Variants(): void
    {
        $thousand = array_map(static fn (int $i): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012x', $i), range(1, 1000));
        $promotion = Promotion::plan(
            PromotionId::fromString(self::ID), 'Tysiąc', PercentOff::of(10), null, new \DateTimeImmutable('2026-12-01T00:00:00Z'), self::variants(...$thousand), null, self::now(),
        );
        self::assertCount(1000, $promotion->variantIds());

        $this->expectInvalid(InvalidPromotion::VARIANT_IDS, static fn () => Promotion::plan(
            PromotionId::fromString(self::ID), 'Pusta', PercentOff::of(10), null, new \DateTimeImmutable('2026-12-01T00:00:00Z'), [], null, self::now(),
        ));
        $this->expectInvalid(InvalidPromotion::VARIANT_IDS, static fn () => Promotion::plan(
            PromotionId::fromString(self::ID), 'Za dużo', PercentOff::of(10), null, new \DateTimeImmutable('2026-12-01T00:00:00Z'),
            self::variants(...[...$thousand, '0192f0c4-7c1a-7c4e-9a6e-ffffffffffff']), null, self::now(),
        ));
    }

    public function testAVariantIsInAPromotionOnce(): void
    {
        $this->expectInvalid(InvalidPromotion::VARIANT_IDS, static fn () => Promotion::plan(
            PromotionId::fromString(self::ID), 'Powtórka', PercentOff::of(10), null, new \DateTimeImmutable('2026-12-01T00:00:00Z'), self::variants(self::A, self::B, self::A), null, self::now(),
        ));
    }

    public function testReviseOfAPlannedPromotionChangesEverything(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');
        $actor = PromotionActor::staff('sub-2', 'Bartek Admin');
        $later = new \DateTimeImmutable('2026-11-10T13:00:00Z');

        $periodChanged = $promotion->revise('Zima', PercentOff::of(35), new \DateTimeImmutable('2026-11-12T00:00:00Z'), new \DateTimeImmutable('2026-11-30T00:00:00Z'), self::variants(self::C, self::B), $actor, $later);

        self::assertTrue($periodChanged);
        self::assertSame('Zima', $promotion->name());
        self::assertSame(35, $promotion->percentOff()->value());
        self::assertSame('2026-11-12T00:00:00+00:00', $promotion->period()->startsAt()->format(\DATE_ATOM));
        self::assertSame('2026-11-30T00:00:00+00:00', $promotion->period()->endsAt()->format(\DATE_ATOM));
        self::assertSame([self::C, self::B], self::ids($promotion->variantIds()));
        self::assertSame($actor, $promotion->updatedBy());
        self::assertNull($promotion->createdBy(), 'the creator stays');
        self::assertEquals($later, $promotion->updatedAt());
        self::assertEquals(self::now(), $promotion->createdAt());
        self::assertSame(2, $promotion->version());
    }

    public function testReviseOfAPlannedPromotionMayStartItNow(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        self::assertTrue($promotion->revise('Od teraz', PercentOff::of(10), null, new \DateTimeImmutable('2026-11-21T00:00:00Z'), self::variants(self::A, self::B), null, self::now()));

        self::assertSame('2026-11-10T12:00:01+00:00', $promotion->period()->startsAt()->format(\DATE_ATOM));
    }

    public function testReviseWithTheSamePeriodTellsThePeriodDidNotChange(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        self::assertFalse($promotion->revise('Nowa nazwa', PercentOff::of(15), new \DateTimeImmutable('2026-11-11T01:00:00+01:00'), new \DateTimeImmutable('2026-11-21T00:00:00.7Z'), self::variants(self::A), null, self::now()));
        self::assertSame(2, $promotion->version(), 'a revise always moves the version');
    }

    public function testReviseOfAPlannedPromotionRefusesAnEndAtOrBeforeNow(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        $this->expectInvalid(InvalidPromotion::ENDS_AT, static fn () => $promotion->revise('X', PercentOff::of(10), null, self::now(), self::variants(self::A), null, self::now()));
    }

    public function testReviseOfAnActivePromotionChangesTheNameAndTheEnd(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');
        $during = new \DateTimeImmutable('2026-11-15T08:00:00Z');
        $actor = PromotionActor::staff('sub-3', 'Celina');

        $periodChanged = $promotion->revise('Przedłużona', PercentOff::of(20), new \DateTimeImmutable('2026-11-11T00:00:00Z'), new \DateTimeImmutable('2026-12-01T00:00:00Z'), self::variants(self::B, self::A), $actor, $during);

        self::assertTrue($periodChanged);
        self::assertSame('Przedłużona', $promotion->name());
        self::assertSame('2026-11-11T00:00:00+00:00', $promotion->period()->startsAt()->format(\DATE_ATOM));
        self::assertSame('2026-12-01T00:00:00+00:00', $promotion->period()->endsAt()->format(\DATE_ATOM));
        self::assertSame([self::A, self::B], self::ids($promotion->variantIds()), 'the same variants in another order are no change');
        self::assertSame($actor, $promotion->updatedBy());
        self::assertEquals($during, $promotion->updatedAt());
    }

    public function testAnActivePromotionCanEndEarlierInTheFuture(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        self::assertTrue($promotion->revise('Krótsza', PercentOff::of(20), new \DateTimeImmutable('2026-11-11T00:00:00Z'), new \DateTimeImmutable('2026-11-16T00:00:00Z'), self::variants(self::A, self::B), null, new \DateTimeImmutable('2026-11-15T08:00:00Z')));

        self::assertSame('2026-11-16T00:00:00+00:00', $promotion->period()->endsAt()->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endsBeforeOrAtTheMomentOfTheRevise(): iterable
    {
        yield 'now' => ['2026-11-15T08:00:00.250000Z'];
        yield 'in the second of now' => ['2026-11-15T08:00:00.900000Z'];
        yield 'an hour ago' => ['2026-11-15T07:00:00Z'];
        yield 'before the start' => ['2026-11-01T00:00:00Z'];
    }

    #[DataProvider('endsBeforeOrAtTheMomentOfTheRevise')]
    public function testAnEndAtOrBeforeNowEndsAnActivePromotionAtTheStartOfNow(string $endsAt): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');
        $now = new \DateTimeImmutable('2026-11-15T08:00:00.250000Z');

        self::assertTrue($promotion->revise('Zakończona', PercentOff::of(20), new \DateTimeImmutable('2026-11-11T00:00:00Z'), new \DateTimeImmutable($endsAt), self::variants(self::A, self::B), null, $now));

        self::assertSame('2026-11-15T08:00:01+00:00', $promotion->period()->endsAt()->format(\DATE_ATOM));
        self::assertSame(PromotionStatus::Active, $promotion->status($now));
        self::assertSame(PromotionStatus::Ended, $promotion->status(new \DateTimeImmutable('2026-11-15T08:00:01Z')));
    }

    public function testTheEndOfAnActivePromotionStays90DaysFromItsStart(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');
        $during = new \DateTimeImmutable('2026-11-15T08:00:00Z');

        $this->expectInvalid(InvalidPromotion::ENDS_AT, static fn () => $promotion->revise('X', PercentOff::of(20), new \DateTimeImmutable('2026-11-11T00:00:00Z'), new \DateTimeImmutable('2027-02-09T00:00:01Z'), self::variants(self::A, self::B), null, $during));
    }

    /**
     * @return iterable<string, array{int, string|null, list<string>}>
     */
    public static function changesOfAnActivePromotionOtherThanNameAndEnd(): iterable
    {
        yield 'the percent' => [25, '2026-11-11T00:00:00Z', [self::A, self::B]];
        yield 'the start' => [20, '2026-11-12T00:00:00Z', [self::A, self::B]];
        yield 'the start from now' => [20, null, [self::A, self::B]];
        yield 'a variant added' => [20, '2026-11-11T00:00:00Z', [self::A, self::B, self::C]];
        yield 'a variant removed' => [20, '2026-11-11T00:00:00Z', [self::A]];
        yield 'a variant replaced' => [20, '2026-11-11T00:00:00Z', [self::A, self::C]];
    }

    /**
     * @param list<string> $variants
     */
    #[DataProvider('changesOfAnActivePromotionOtherThanNameAndEnd')]
    public function testAnActivePromotionChangesOnlyItsNameAndEnd(int $percent, ?string $startsAt, array $variants): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        try {
            $promotion->revise('Inna', PercentOff::of($percent), null === $startsAt ? null : new \DateTimeImmutable($startsAt), new \DateTimeImmutable('2026-11-25T00:00:00Z'), self::variants(...$variants), null, new \DateTimeImmutable('2026-11-15T08:00:00Z'));
            self::fail('The change must be refused.');
        } catch (PromotionNotEditable $notEditable) {
            self::assertSame(PromotionNotEditable::afterStart()->getMessage(), $notEditable->getMessage());
        }

        self::assertSame('Jesień', $promotion->name());
        self::assertSame('2026-11-21T00:00:00+00:00', $promotion->period()->endsAt()->format(\DATE_ATOM));
        self::assertSame(1, $promotion->version());
    }

    public function testAnEndedPromotionCannotBeRevised(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        $this->expectExceptionObject(PromotionNotEditable::ended());

        $promotion->revise('Jesień', PercentOff::of(20), new \DateTimeImmutable('2026-11-11T00:00:00Z'), new \DateTimeImmutable('2026-11-25T00:00:00Z'), self::variants(self::A, self::B), null, new \DateTimeImmutable('2026-11-21T00:00:00Z'));
    }

    public function testOnlyAPlannedPromotionIsDeletable(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        $promotion->assertDeletable(new \DateTimeImmutable('2026-11-10T23:59:59Z'));

        foreach (['2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z'] as $moment) {
            try {
                $promotion->assertDeletable(new \DateTimeImmutable($moment));
                self::fail('Only a planned promotion is deletable.');
            } catch (PromotionNotEditable $notEditable) {
                self::assertSame(PromotionNotEditable::notDeletable()->getMessage(), $notEditable->getMessage());
            }
        }
    }

    public function testNewVariantsAreTheOnesNotInThePromotion(): void
    {
        $promotion = $this->planned('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        self::assertSame([self::C], self::ids($promotion->newVariantsAmong(self::variants(self::B, self::C, self::A))));
    }

    public function testReconstituteRestoresTheStoredState(): void
    {
        $actor = PromotionActor::staff('sub-1', 'Anna');
        $promotion = Promotion::reconstitute(
            PromotionId::fromString(self::ID), 'Zapisana', PercentOff::of(5),
            PromotionPeriod::of(new \DateTimeImmutable('2026-01-01T00:00:00Z'), new \DateTimeImmutable('2026-01-10T00:00:00Z')),
            self::variants(self::A), new \DateTimeImmutable('2025-12-01T00:00:00Z'), null, new \DateTimeImmutable('2025-12-02T00:00:00Z'), $actor, 7,
        );

        self::assertSame(7, $promotion->version());
        self::assertSame('Zapisana', $promotion->name());
        self::assertNull($promotion->createdBy());
        self::assertSame($actor, $promotion->updatedBy());
        self::assertSame(PromotionStatus::Ended, $promotion->status(self::now()));
    }

    private function planned(string $startsAt, string $endsAt): Promotion
    {
        return Promotion::plan(
            PromotionId::fromString(self::ID),
            'Jesień',
            PercentOff::of(20),
            new \DateTimeImmutable($startsAt),
            new \DateTimeImmutable($endsAt),
            self::variants(self::A, self::B),
            null,
            self::now(),
        );
    }

    private function expectInvalid(string $field, \Closure $action): void
    {
        try {
            $action();
            self::fail('The promotion must be refused at '.$field.'.');
        } catch (InvalidPromotion $invalid) {
            self::assertSame($field, $invalid->field);
        }
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /**
     * @return list<VariantId>
     */
    private static function variants(string ...$ids): array
    {
        return array_values(array_map(VariantId::fromString(...), $ids));
    }

    /**
     * @param list<VariantId> $variantIds
     *
     * @return list<string>
     */
    private static function ids(array $variantIds): array
    {
        return array_map(static fn (VariantId $id): string => $id->toString(), $variantIds);
    }
}
