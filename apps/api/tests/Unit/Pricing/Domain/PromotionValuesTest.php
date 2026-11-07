<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\InvalidPromotion;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PriceLockedByPromotion;
use App\Pricing\Domain\Promotion\PromotionActor;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionModifiedConcurrently;
use App\Pricing\Domain\Promotion\PromotionNotEditable;
use App\Pricing\Domain\Promotion\PromotionNotFound;
use App\Pricing\Domain\Promotion\PromotionOverlap;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\ExtendedProblem;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Domain\Exception\TypedProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromotionValuesTest extends TestCase
{
    public function testPercentOffIsFrom1To90(): void
    {
        self::assertSame(1, PercentOff::of(1)->value());
        self::assertSame(90, PercentOff::of(90)->value());
        self::assertTrue(PercentOff::of(20)->equals(PercentOff::of(20)));
        self::assertFalse(PercentOff::of(20)->equals(PercentOff::of(21)));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function percentsOutOfRange(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield '91' => [91];
        yield '100' => [100];
    }

    #[DataProvider('percentsOutOfRange')]
    public function testPercentOffOutOfRangeIsInvalid(int $percent): void
    {
        try {
            PercentOff::of($percent);
            self::fail('A percent out of range must be refused.');
        } catch (InvalidPromotion $invalid) {
            self::assertSame(InvalidPromotion::PERCENT_OFF, $invalid->field);
            self::assertContains(InvalidInput::class, class_implements($invalid));
        }
    }

    public function testAPeriodIsTruncatedToTheSecondInUtc(): void
    {
        $period = PromotionPeriod::of(
            new \DateTimeImmutable('2026-11-10T10:00:00.999999+01:00'),
            new \DateTimeImmutable('2026-11-20T12:30:15.5+01:00'),
        );

        self::assertSame('2026-11-10T09:00:00.000000+00:00', $period->startsAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2026-11-20T11:30:15.000000+00:00', $period->endsAt()->format('Y-m-d\TH:i:s.uP'));
    }

    public function testAPeriodIsActiveFromItsStartUntilBeforeItsEnd(): void
    {
        $period = PromotionPeriod::of(new \DateTimeImmutable('2026-11-10T10:00:00Z'), new \DateTimeImmutable('2026-11-20T10:00:00Z'));

        self::assertFalse($period->isActiveAt(new \DateTimeImmutable('2026-11-10T09:59:59.999999Z')));
        self::assertTrue($period->isActiveAt(new \DateTimeImmutable('2026-11-10T10:00:00Z')));
        self::assertTrue($period->isActiveAt(new \DateTimeImmutable('2026-11-20T09:59:59.999999Z')));
        self::assertFalse($period->isActiveAt(new \DateTimeImmutable('2026-11-20T10:00:00Z')));
    }

    public function testPeriodsOverlapWhenOneStartsBeforeTheOtherEnds(): void
    {
        $period = PromotionPeriod::of(new \DateTimeImmutable('2026-11-10T10:00:00Z'), new \DateTimeImmutable('2026-11-20T10:00:00Z'));

        self::assertTrue($period->overlaps(PromotionPeriod::of(new \DateTimeImmutable('2026-11-19T00:00:00Z'), new \DateTimeImmutable('2026-11-25T00:00:00Z'))));
        self::assertTrue($period->overlaps(PromotionPeriod::of(new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-11-10T10:00:01Z'))));
        self::assertTrue($period->overlaps(PromotionPeriod::of(new \DateTimeImmutable('2026-11-12T00:00:00Z'), new \DateTimeImmutable('2026-11-13T00:00:00Z'))));
        self::assertFalse($period->overlaps(PromotionPeriod::of(new \DateTimeImmutable('2026-11-20T10:00:00Z'), new \DateTimeImmutable('2026-11-25T00:00:00Z'))), 'the end is not part of the period');
        self::assertFalse($period->overlaps(PromotionPeriod::of(new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-11-10T10:00:00Z'))));
    }

    public function testAPeriodMayLastExactly90Days(): void
    {
        $period = PromotionPeriod::of(new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2027-01-30T00:00:00Z'));

        self::assertSame('2027-01-30T00:00:00+00:00', $period->endsAt()->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPeriods(): iterable
    {
        yield 'the end before the start' => ['2026-11-10T10:00:00Z', '2026-11-10T09:00:00Z'];
        yield 'the end at the start' => ['2026-11-10T10:00:00Z', '2026-11-10T10:00:00Z'];
        yield 'the end in the second of the start' => ['2026-11-10T10:00:00Z', '2026-11-10T10:00:00.9Z'];
        yield '90 days and a second' => ['2026-11-01T00:00:00Z', '2027-01-30T00:00:01Z'];
    }

    #[DataProvider('invalidPeriods')]
    public function testAnEmptyOrTooLongPeriodIsInvalidAtTheEnd(string $startsAt, string $endsAt): void
    {
        try {
            PromotionPeriod::of(new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt));
            self::fail('The period must be refused.');
        } catch (InvalidPromotion $invalid) {
            self::assertSame(InvalidPromotion::ENDS_AT, $invalid->field);
        }
    }

    public function testPromotionIdIsALowerCaseUuid(): void
    {
        $id = PromotionId::fromString('0192F0C4-7C1A-7C4E-9A6E-3F1D2B4C5A60');

        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', $id->toString());
        self::assertTrue($id->equals(PromotionId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60')));
        $this->expectException(\InvalidArgumentException::class);
        PromotionId::fromString('promotion-1');
    }

    public function testAStaffActorNeedsAnIdAndAName(): void
    {
        $actor = PromotionActor::staff('sub-1', 'Anna Admin');
        self::assertSame(['sub-1', 'Anna Admin'], [$actor->id(), $actor->name()]);

        $this->expectException(\InvalidArgumentException::class);
        PromotionActor::staff('', 'Anna Admin');
    }

    public function testTheConflictsHaveStableTypes(): void
    {
        $locked = new PriceLockedByPromotion();
        self::assertContains(Conflict::class, class_implements($locked));
        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/price-locked-by-promotion', $locked->problemType());
        self::assertSame('Cena wariantu w aktywnej promocji nie może się zmienić. Zakończ promocję, żeby zmienić cenę.', $locked->getMessage());

        $notEditable = PromotionNotEditable::afterStart();
        self::assertContains(Conflict::class, class_implements($notEditable));
        self::assertContains(TypedProblem::class, class_implements($notEditable));
        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/promotion-not-editable', $notEditable->problemType());
        self::assertSame($notEditable->problemType(), PromotionNotEditable::ended()->problemType());
        self::assertSame($notEditable->problemType(), PromotionNotEditable::notDeletable()->problemType());

        $overlap = new PromotionOverlap([VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61'), VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62')]);
        self::assertContains(Conflict::class, class_implements($overlap));
        self::assertContains(ExtendedProblem::class, class_implements($overlap));
        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/promotion-overlap', $overlap->problemType());
        self::assertSame('Część wariantów jest już w innej promocji w tym okresie.', $overlap->getMessage());
        self::assertSame(['conflictingVariantIds' => ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62']], $overlap->problemExtensions());

        self::assertContains(Conflict::class, class_implements(PromotionModifiedConcurrently::class));
        self::assertNotContains(TypedProblem::class, class_implements(PromotionModifiedConcurrently::class));
        self::assertContains(NotFound::class, class_implements(PromotionNotFound::class));
    }
}
