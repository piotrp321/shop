<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeActor;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeNotEditable;
use App\Pricing\Domain\DiscountCode\DiscountCodeRejectionReason;
use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\InvalidDiscountCode;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The discount code aggregate: status, the reasons a code is refused (D6, Q7) and the edit rules (Q3).
 */
final class DiscountCodeTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6a00';
    private const string NOW = '2026-11-10T12:00:00.400000Z';
    private const string STARTED = '2026-11-01T00:00:00Z';
    private const string LATER = '2026-11-20T00:00:00Z';

    public function testCreateKeepsTheValuesAndRecordsTheActor(): void
    {
        $actor = DiscountCodeActor::staff('sub-1', 'Ola Marketing');

        $code = DiscountCode::create(
            DiscountCodeId::fromString(self::ID),
            DiscountCodeValue::of(' minus50 '),
            DiscountValue::of(DiscountKind::Amount, 5000),
            MinimumOrder::of(30000),
            ValidityPeriod::of(new \DateTimeImmutable('2026-11-11T00:00:00+01:00'), null),
            UsageLimit::of(100),
            true,
            $actor,
            self::now(),
        );

        self::assertSame(self::ID, $code->id()->toString());
        self::assertSame('MINUS50', $code->code()->toString());
        self::assertSame(DiscountKind::Amount, $code->value()->kind());
        self::assertSame(5000, $code->value()->value());
        self::assertSame(30000, $code->minimumOrder()?->gross());
        self::assertSame('2026-11-10T23:00:00+00:00', $code->period()->startsAt()->format(\DATE_ATOM));
        self::assertNull($code->period()->endsAt());
        self::assertSame(100, $code->usageLimit()?->value());
        self::assertTrue($code->isEnabled());
        self::assertSame($actor, $code->createdBy());
        self::assertSame($actor, $code->updatedBy());
        self::assertEquals(self::now(), $code->createdAt());
        self::assertEquals(self::now(), $code->updatedAt());
        self::assertSame(1, $code->version());
    }

    public function testACodeMayStartInThePastAndBeCreatedDisabledWithoutAnActor(): void
    {
        $code = DiscountCode::create(
            DiscountCodeId::fromString(self::ID),
            DiscountCodeValue::of('LATO15'),
            DiscountValue::of(DiscountKind::Percent, 15),
            null,
            ValidityPeriod::of(new \DateTimeImmutable('2026-09-01T00:00:00Z'), new \DateTimeImmutable('2026-10-01T00:00:00Z')),
            null,
            false,
            null,
            self::now(),
        );

        self::assertFalse($code->isEnabled());
        self::assertNull($code->createdBy());
        self::assertNull($code->minimumOrder());
        self::assertNull($code->usageLimit());
    }

    /**
     * @return iterable<string, array{int|null}>
     */
    public static function minimumsNotAboveTheAmount(): iterable
    {
        yield 'no minimum' => [null];
        yield 'the minimum equal to the amount' => [5000];
        yield 'the minimum below the amount' => [4999];
    }

    #[DataProvider('minimumsNotAboveTheAmount')]
    public function testAnAmountCodeNeedsAMinimumAboveItsAmount(?int $minimum): void
    {
        try {
            DiscountCode::create(
                DiscountCodeId::fromString(self::ID),
                DiscountCodeValue::of('MINUS50'),
                DiscountValue::of(DiscountKind::Amount, 5000),
                null === $minimum ? null : MinimumOrder::of($minimum),
                ValidityPeriod::of(new \DateTimeImmutable(self::STARTED), null),
                null,
                true,
                null,
                self::now(),
            );
            self::fail('The code must be refused.');
        } catch (InvalidDiscountCode $refused) {
            self::assertSame(InvalidDiscountCode::MINIMUM_ORDER, $refused->field);
            self::assertSame('Kod kwotowy wymaga minimum większego od kwoty rabatu.', $refused->getMessage());
        }
    }

    public function testAnAmountCodeWithAMinimumOneGroszAboveItsAmountIsCreated(): void
    {
        $code = $this->amountCode(5000, 5001, self::STARTED, null, null);

        self::assertSame(5001, $code->minimumOrder()?->gross());
    }

    public function testThePlannedStatusLastsUntilBeforeTheStartAndTheActiveUntilBeforeTheEnd(): void
    {
        $code = $this->percentCode(10, null, '2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z', null);

        self::assertSame(DiscountCodeStatus::Planned, $code->status(new \DateTimeImmutable('2026-11-10T23:59:59.999999Z')));
        self::assertSame(DiscountCodeStatus::Active, $code->status(new \DateTimeImmutable('2026-11-11T00:00:00Z')));
        self::assertSame(DiscountCodeStatus::Active, $code->status(new \DateTimeImmutable('2026-11-20T23:59:59.999999Z')));
        self::assertSame(DiscountCodeStatus::Expired, $code->status(new \DateTimeImmutable('2026-11-21T00:00:00Z')));
    }

    public function testACodeWithoutAnEndNeverExpires(): void
    {
        $code = $this->percentCode(10, null, self::STARTED, null, null);

        self::assertSame(DiscountCodeStatus::Active, $code->status(new \DateTimeImmutable('9999-12-31T23:59:59Z')));
    }

    public function testDisabledComesBeforeEveryOtherStatus(): void
    {
        $code = $this->percentCode(10, null, '2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z', null, enabled: false);

        foreach (['2026-11-10T00:00:00Z', '2026-11-15T00:00:00Z', '2026-11-25T00:00:00Z'] as $moment) {
            self::assertSame(DiscountCodeStatus::Disabled, $code->status(new \DateTimeImmutable($moment)), $moment);
        }
    }

    public function testAnActiveCodeAboveItsMinimumAndBelowItsLimitIsNotRefused(): void
    {
        $code = $this->percentCode(10, 30000, self::STARTED, self::LATER, 5);

        self::assertNull($code->rejectionFor(30000, 4, self::now()));
    }

    /**
     * @return iterable<string, array{bool, string, string|null}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'disabled' => [false, self::STARTED, null];
        yield 'not started yet' => [true, '2026-11-10T12:00:01Z', null];
        yield 'at its end' => [true, self::STARTED, '2026-11-10T12:00:00Z'];
        yield 'expired' => [true, self::STARTED, '2026-11-05T00:00:00Z'];
    }

    #[DataProvider('invalidCodes')]
    public function testADisabledPlannedOrExpiredCodeIsInvalidBeforeAnyOtherReason(bool $enabled, string $startsAt, ?string $endsAt): void
    {
        // Used up and below its minimum too: the first reason wins.
        $code = $this->percentCode(10, 30000, $startsAt, $endsAt, 1, enabled: $enabled);

        $rejection = $code->rejectionFor(100, 1, self::now());

        self::assertNotNull($rejection);
        self::assertSame(DiscountCodeRejectionReason::Invalid, $rejection->reason);
        self::assertNull($rejection->minimumOrderGross);
    }

    public function testAnActiveCodeAtItsLimitIsUsedUpBeforeItIsBelowItsMinimum(): void
    {
        $code = $this->percentCode(10, 30000, self::STARTED, null, 3);

        $rejection = $code->rejectionFor(100, 3, self::now());

        self::assertNotNull($rejection);
        self::assertSame(DiscountCodeRejectionReason::UsedUp, $rejection->reason);
        self::assertNull($rejection->minimumOrderGross);
        self::assertSame(DiscountCodeRejectionReason::UsedUp, $code->rejectionFor(30000, 4, self::now())?->reason, 'above the limit too');
    }

    public function testACodeWithoutALimitIsNeverUsedUp(): void
    {
        self::assertNull($this->percentCode(10, null, self::STARTED, null, null)->rejectionFor(10000, 1_000_000, self::now()));
    }

    public function testASubtotalBelowTheMinimumOfTheCodeIsBelowMinimumWithTheMinimum(): void
    {
        $code = $this->amountCode(5000, 30000, self::STARTED, null, null);

        $rejection = $code->rejectionFor(29999, 0, self::now());

        self::assertNotNull($rejection);
        self::assertSame(DiscountCodeRejectionReason::BelowMinimum, $rejection->reason);
        self::assertSame(30000, $rejection->minimumOrderGross);
        self::assertNull($code->rejectionFor(30000, 0, self::now()), 'the minimum itself is enough');
    }

    public function testAPercentCodeBelowItsImplicitMinimumIsBelowMinimumWithThatMinimum(): void
    {
        // ceil(100 / 3) = 34: 33 grosze at 3 % give no discount (Q7).
        $code = $this->percentCode(3, null, self::STARTED, null, null);

        $rejection = $code->rejectionFor(33, 0, self::now());

        self::assertNotNull($rejection);
        self::assertSame(DiscountCodeRejectionReason::BelowMinimum, $rejection->reason);
        self::assertSame(34, $rejection->minimumOrderGross);
        self::assertSame(34, $code->effectiveMinimumGross());
    }

    public function testAPercentCodeAtItsImplicitMinimumGivesAtLeastOneGrosz(): void
    {
        $code = $this->percentCode(3, null, self::STARTED, null, null);

        self::assertNull($code->rejectionFor(34, 0, self::now()));
        self::assertGreaterThanOrEqual(1, intdiv(34 * 3, 100));
    }

    public function testALargerMinimumOfThePercentCodeWins(): void
    {
        $code = $this->percentCode(3, 5000, self::STARTED, null, null);

        self::assertSame(5000, $code->effectiveMinimumGross());
        self::assertSame(5000, $code->rejectionFor(4999, 0, self::now())?->minimumOrderGross);
    }

    public function testASmallerMinimumOfThePercentCodeGivesWayToTheImplicitOne(): void
    {
        $code = $this->percentCode(1, 50, self::STARTED, null, null);

        self::assertSame(100, $code->effectiveMinimumGross());
        self::assertSame(100, $code->rejectionFor(99, 0, self::now())?->minimumOrderGross);
    }

    public function testEachReasonHasItsProblemTypeAndPolishDetail(): void
    {
        $invalid = $this->percentCode(10, null, self::STARTED, null, null, enabled: false)->rejectionFor(10000, 0, self::now());
        $usedUp = $this->percentCode(10, null, self::STARTED, null, 1)->rejectionFor(10000, 1, self::now());
        $belowMinimum = $this->amountCode(5000, 30000, self::STARTED, null, null)->rejectionFor(100, 0, self::now());
        $belowAMillion = $this->percentCode(10, 100_000_000, self::STARTED, null, null)->rejectionFor(100, 0, self::now());
        $belowGrosze = $this->percentCode(3, null, self::STARTED, null, null)->rejectionFor(1, 0, self::now());

        self::assertNotNull($invalid);
        self::assertNotNull($usedUp);
        self::assertNotNull($belowMinimum);
        self::assertNotNull($belowAMillion);
        self::assertNotNull($belowGrosze);
        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/discount-code-invalid', $invalid->problemType());
        self::assertSame('Ten kod jest nieprawidłowy albo nieaktywny.', $invalid->detail());
        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/discount-code-used-up', $usedUp->problemType());
        self::assertSame('Ten kod został już wykorzystany.', $usedUp->detail());
        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/discount-code-below-minimum', $belowMinimum->problemType());
        self::assertSame('Ten kod działa przy zamówieniu od 300,00 zł.', $belowMinimum->detail());
        self::assertSame('Ten kod działa przy zamówieniu od 1 000 000,00 zł.', $belowAMillion->detail());
        self::assertSame('Ten kod działa przy zamówieniu od 0,34 zł.', $belowGrosze->detail());
    }

    public function testAPlannedCodeChangesEverythingButItsText(): void
    {
        $code = $this->percentCode(10, null, '2026-11-15T00:00:00Z', null, null);
        $actor = DiscountCodeActor::staff('sub-2', 'Bartek');

        $code->revise(
            DiscountValue::of(DiscountKind::Amount, 2000),
            MinimumOrder::of(10000),
            ValidityPeriod::of(new \DateTimeImmutable('2026-11-16T00:00:00Z'), new \DateTimeImmutable('2026-11-30T00:00:00Z')),
            UsageLimit::of(10),
            false,
            0,
            $actor,
            self::now(),
        );

        self::assertSame('WITAJ10', $code->code()->toString());
        self::assertTrue($code->value()->equals(DiscountValue::of(DiscountKind::Amount, 2000)));
        self::assertSame(10000, $code->minimumOrder()?->gross());
        self::assertSame('2026-11-16T00:00:00+00:00', $code->period()->startsAt()->format(\DATE_ATOM));
        self::assertSame('2026-11-30T00:00:00+00:00', $code->period()->endsAt()?->format(\DATE_ATOM));
        self::assertSame(10, $code->usageLimit()?->value());
        self::assertFalse($code->isEnabled());
        self::assertSame($actor, $code->updatedBy());
        self::assertNull($code->createdBy());
        self::assertEquals(self::now(), $code->updatedAt());
        self::assertSame(2, $code->version());
    }

    public function testAPlannedCodeMayStartAtOnceAndGetALimit(): void
    {
        $code = $this->percentCode(10, null, '2026-11-15T00:00:00Z', null, null);

        $code->revise(DiscountValue::of(DiscountKind::Percent, 10), null, ValidityPeriod::of(new \DateTimeImmutable('2026-11-01T00:00:00Z'), null), UsageLimit::of(5), true, 0, null, self::now());

        self::assertSame(DiscountCodeStatus::Active, $code->status(self::now()));
        self::assertSame(5, $code->usageLimit()?->value());
    }

    public function testADisabledCodeThatHasNotStartedIsPlannedForItsChanges(): void
    {
        $code = $this->percentCode(10, null, '2026-11-15T00:00:00Z', null, null, enabled: false);

        $code->revise(DiscountValue::of(DiscountKind::Percent, 20), MinimumOrder::of(100), ValidityPeriod::of(new \DateTimeImmutable('2026-11-16T00:00:00Z'), null), UsageLimit::of(3), true, 0, null, self::now());

        self::assertSame(20, $code->value()->value());
        self::assertSame(3, $code->usageLimit()?->value());
    }

    public function testARevisedAmountCodeStillNeedsAMinimumAboveItsAmount(): void
    {
        $code = $this->percentCode(10, null, '2026-11-15T00:00:00Z', null, null);

        $this->expectExceptionObject(InvalidDiscountCode::minimumNotAboveAmount());
        $code->revise(DiscountValue::of(DiscountKind::Amount, 2000), MinimumOrder::of(2000), $code->period(), null, true, 0, null, self::now());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function startedCodes(): iterable
    {
        yield 'active' => [self::STARTED, self::LATER];
        yield 'active without an end' => [self::STARTED, null];
        yield 'started in this very second' => ['2026-11-10T12:00:00Z', null];
        yield 'expired' => ['2026-10-01T00:00:00Z', '2026-11-01T00:00:00Z'];
    }

    #[DataProvider('startedCodes')]
    public function testAStartedCodeChangesItsEndEnabledAndItsLimit(string $startsAt, ?string $endsAt): void
    {
        $code = $this->percentCode(10, 5000, $startsAt, $endsAt, 10);

        $code->revise(
            DiscountValue::of(DiscountKind::Percent, 10),
            MinimumOrder::of(5000),
            ValidityPeriod::of(new \DateTimeImmutable($startsAt), new \DateTimeImmutable('2026-12-31T00:00:00Z')),
            UsageLimit::of(4),
            false,
            4,
            null,
            self::now(),
        );

        self::assertSame('2026-12-31T00:00:00+00:00', $code->period()->endsAt()?->format(\DATE_ATOM));
        self::assertSame(4, $code->usageLimit()?->value());
        self::assertFalse($code->isEnabled());
        self::assertSame(2, $code->version());
    }

    public function testAStartedCodeMayLoseItsLimitAndItsEnd(): void
    {
        $code = $this->percentCode(10, null, self::STARTED, self::LATER, 10);

        $code->revise(DiscountValue::of(DiscountKind::Percent, 10), null, ValidityPeriod::of(new \DateTimeImmutable(self::STARTED), null), null, true, 7, null, self::now());

        self::assertNull($code->usageLimit());
        self::assertNull($code->period()->endsAt());
    }

    public function testAnExpiredCodeIsEnabledAgainWithItsEndUnchanged(): void
    {
        $code = $this->percentCode(10, null, '2026-10-01T00:00:00Z', '2026-11-01T00:00:00Z', null, enabled: false);

        $code->revise(DiscountValue::of(DiscountKind::Percent, 10), null, $code->period(), null, true, 0, null, self::now());

        self::assertTrue($code->isEnabled());
        self::assertSame(DiscountCodeStatus::Expired, $code->status(self::now()));
    }

    /**
     * @return iterable<string, array{\Closure(DiscountCode): void}>
     */
    public static function changesAfterTheStart(): iterable
    {
        $period = static fn (?string $endsAt = self::LATER): ValidityPeriod => ValidityPeriod::of(new \DateTimeImmutable(self::STARTED), null === $endsAt ? null : new \DateTimeImmutable($endsAt));

        yield 'another percent' => [static fn (DiscountCode $code) => $code->revise(DiscountValue::of(DiscountKind::Percent, 11), MinimumOrder::of(5000), $period(), UsageLimit::of(10), true, 0, null, self::now())];
        yield 'another kind' => [static fn (DiscountCode $code) => $code->revise(DiscountValue::of(DiscountKind::Amount, 1000), MinimumOrder::of(5000), $period(), UsageLimit::of(10), true, 0, null, self::now())];
        yield 'another minimum' => [static fn (DiscountCode $code) => $code->revise(DiscountValue::of(DiscountKind::Percent, 10), MinimumOrder::of(5001), $period(), UsageLimit::of(10), true, 0, null, self::now())];
        yield 'no minimum' => [static fn (DiscountCode $code) => $code->revise(DiscountValue::of(DiscountKind::Percent, 10), null, $period(), UsageLimit::of(10), true, 0, null, self::now())];
        yield 'another start' => [static fn (DiscountCode $code) => $code->revise(DiscountValue::of(DiscountKind::Percent, 10), MinimumOrder::of(5000), ValidityPeriod::of(new \DateTimeImmutable('2026-11-01T00:00:01Z'), new \DateTimeImmutable(self::LATER)), UsageLimit::of(10), true, 0, null, self::now())];
        yield 'a start in the future' => [static fn (DiscountCode $code) => $code->revise(DiscountValue::of(DiscountKind::Percent, 10), MinimumOrder::of(5000), ValidityPeriod::of(new \DateTimeImmutable('2026-11-15T00:00:00Z'), new \DateTimeImmutable(self::LATER)), UsageLimit::of(10), true, 0, null, self::now())];
    }

    /**
     * @param \Closure(DiscountCode): void $revise
     */
    #[DataProvider('changesAfterTheStart')]
    public function testAStartedCodeRefusesAnyOtherChange(\Closure $revise): void
    {
        $code = $this->percentCode(10, 5000, self::STARTED, self::LATER, 10);

        try {
            $revise($code);
            self::fail('The change must be refused.');
        } catch (DiscountCodeNotEditable $refused) {
            self::assertSame('tag:piotrp321.github.io,2026:shop/problems/discount-code-not-editable', $refused->problemType());
            self::assertSame('Tej zmiany nie można wprowadzić w kodzie, który już obowiązuje albo był użyty.', $refused->getMessage());
        }
        self::assertSame(1, $code->version());
        self::assertSame(10, $code->value()->value());
    }

    public function testAStartedCodeAcceptsTheSameStartGivenInAnotherZoneOrWithAFraction(): void
    {
        $code = $this->percentCode(10, null, self::STARTED, null, null);

        $code->revise(DiscountValue::of(DiscountKind::Percent, 10), null, ValidityPeriod::of(new \DateTimeImmutable('2026-11-01T01:00:00.900000+01:00'), null), null, false, 0, null, self::now());

        self::assertFalse($code->isEnabled());
    }

    #[DataProvider('startedCodes')]
    public function testALimitIsNotAddedToAStartedCodeWithoutOne(string $startsAt, ?string $endsAt): void
    {
        $code = $this->percentCode(10, null, $startsAt, $endsAt, null);

        $this->expectException(DiscountCodeNotEditable::class);
        $code->revise(DiscountValue::of(DiscountKind::Percent, 10), null, $code->period(), UsageLimit::of(1_000_000), true, 0, null, self::now());
    }

    public function testALimitBelowTheActiveUsesIsRefusedWithTheirNumber(): void
    {
        $code = $this->percentCode(10, null, self::STARTED, null, 10);

        try {
            $code->revise(DiscountValue::of(DiscountKind::Percent, 10), null, $code->period(), UsageLimit::of(6), true, 7, null, self::now());
            self::fail('The limit must be refused.');
        } catch (InvalidDiscountCode $refused) {
            self::assertSame(InvalidDiscountCode::USAGE_LIMIT, $refused->field);
            self::assertSame('Limit nie może być mniejszy od liczby użyć (7).', $refused->getMessage());
        }
        self::assertSame(10, $code->usageLimit()?->value());
    }

    public function testALimitEqualToTheActiveUsesIsAccepted(): void
    {
        $code = $this->percentCode(10, null, self::STARTED, null, 10);

        $code->revise(DiscountValue::of(DiscountKind::Percent, 10), null, $code->period(), UsageLimit::of(7), true, 7, null, self::now());

        self::assertSame(7, $code->usageLimit()?->value());
    }

    /**
     * @return iterable<string, array{string, string|null, string, string}>
     */
    public static function endsNotLaterThanNow(): iterable
    {
        yield 'a started code ended now' => [self::STARTED, self::LATER, self::STARTED, self::NOW];
        yield 'a started code ended in the second of now' => [self::STARTED, self::LATER, self::STARTED, '2026-11-10T12:00:00.900000Z'];
        yield 'a started code ended in the past' => [self::STARTED, null, self::STARTED, '2026-11-05T00:00:00Z'];
        yield 'an expired code given another past end' => ['2026-10-01T00:00:00Z', '2026-11-01T00:00:00Z', '2026-10-01T00:00:00Z', '2026-11-02T00:00:00Z'];
        yield 'a planned code given a past period' => ['2026-11-15T00:00:00Z', null, '2026-11-05T00:00:00Z', '2026-11-06T00:00:00Z'];
    }

    #[DataProvider('endsNotLaterThanNow')]
    public function testAChangedEndNotLaterThanNowIsRefused(string $storedStart, ?string $storedEnd, string $newStart, string $newEnd): void
    {
        $code = $this->percentCode(10, null, $storedStart, $storedEnd, null);

        try {
            $code->revise(DiscountValue::of(DiscountKind::Percent, 10), null, ValidityPeriod::of(new \DateTimeImmutable($newStart), new \DateTimeImmutable($newEnd)), null, true, 0, null, self::now());
            self::fail('The end must be refused.');
        } catch (InvalidDiscountCode $refused) {
            self::assertSame(InvalidDiscountCode::ENDS_AT, $refused->field);
            self::assertSame('Koniec musi być w przyszłości.', $refused->getMessage());
        }
        self::assertSame(1, $code->version());
    }

    public function testAnEndInTheNextSecondIsAccepted(): void
    {
        $code = $this->percentCode(10, null, self::STARTED, self::LATER, null);

        $code->revise(DiscountValue::of(DiscountKind::Percent, 10), null, ValidityPeriod::of(new \DateTimeImmutable(self::STARTED), new \DateTimeImmutable('2026-11-10T12:00:01Z')), null, true, 0, null, self::now());

        self::assertSame(DiscountCodeStatus::Active, $code->status(self::now()));
    }

    public function testEveryReviseRaisesTheVersionAlsoWithoutAChange(): void
    {
        $code = $this->percentCode(10, null, self::STARTED, null, null);

        $code->revise($code->value(), null, $code->period(), null, true, 0, null, self::now());
        $code->revise($code->value(), null, $code->period(), null, true, 0, null, self::now());

        self::assertSame(3, $code->version());
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function percentCode(int $percent, ?int $minimum, string $startsAt, ?string $endsAt, ?int $limit, bool $enabled = true): DiscountCode
    {
        return DiscountCode::create(
            DiscountCodeId::fromString(self::ID),
            DiscountCodeValue::of('WITAJ10'),
            DiscountValue::of(DiscountKind::Percent, $percent),
            null === $minimum ? null : MinimumOrder::of($minimum),
            ValidityPeriod::of(new \DateTimeImmutable($startsAt), null === $endsAt ? null : new \DateTimeImmutable($endsAt)),
            null === $limit ? null : UsageLimit::of($limit),
            $enabled,
            null,
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
    }

    private function amountCode(int $amount, int $minimum, string $startsAt, ?string $endsAt, ?int $limit): DiscountCode
    {
        return DiscountCode::create(
            DiscountCodeId::fromString(self::ID),
            DiscountCodeValue::of('MINUS50'),
            DiscountValue::of(DiscountKind::Amount, $amount),
            MinimumOrder::of($minimum),
            ValidityPeriod::of(new \DateTimeImmutable($startsAt), null === $endsAt ? null : new \DateTimeImmutable($endsAt)),
            null === $limit ? null : UsageLimit::of($limit),
            true,
            null,
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
    }
}
