<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Command\DiscountCodeRejected;
use App\Pricing\Application\Command\RedeemDiscountCode;
use App\Pricing\Application\Command\RedeemDiscountCodeHandler;
use App\Pricing\Application\Command\ReleaseDiscountCodeUsage;
use App\Pricing\Application\Command\ReleaseDiscountCodeUsageHandler;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeRejectionReason;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageId;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\OrderId;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryDiscountCodeRepository;
use App\Tests\Unit\Pricing\InMemoryDiscountCodeUsages;
use App\Tests\Unit\Pricing\PricingJournal;
use PHPUnit\Framework\TestCase;

/**
 * RedeemDiscountCode and ReleaseDiscountCodeUsage with in-memory adapters (decisions D4, D7, A3, A4).
 */
final class DiscountCodeRedemptionTest extends TestCase
{
    private const string CODE = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6d00';
    private const string ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000aa';
    private const string OTHER_ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000bb';
    private const string NOW = '2026-11-10T12:00:00.400000Z';

    private PricingJournal $journal;
    private InMemoryDiscountCodeRepository $codes;
    private InMemoryDiscountCodeUsages $usages;
    private FixedPricingClock $clock;
    private RedeemDiscountCodeHandler $redeem;
    private ReleaseDiscountCodeUsageHandler $release;

    protected function setUp(): void
    {
        $this->journal = new PricingJournal();
        $this->codes = new InMemoryDiscountCodeRepository($this->journal);
        $this->usages = new InMemoryDiscountCodeUsages($this->journal);
        $this->clock = new FixedPricingClock(self::NOW, $this->journal);
        $this->redeem = new RedeemDiscountCodeHandler($this->codes, $this->usages, $this->clock);
        $this->release = new ReleaseDiscountCodeUsageHandler($this->usages, $this->clock);
    }

    public function testACodeWithoutALimitIsReadAnewWithoutALockAndItsUsageRecorded(): void
    {
        $this->codes->store(self::code());

        ($this->redeem)(new RedeemDiscountCode(self::CODE, self::ORDER, 10_000));

        self::assertSame(['find', 'clock'], $this->journal->entries, 'no lock and no count of the usages');
        $usage = $this->usageOf(self::ORDER);
        self::assertNotNull($usage);
        self::assertSame(self::CODE, $usage->codeId()->toString());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $usage->usedAt());
        self::assertTrue($usage->isActive());
    }

    public function testACodeWithALimitIsLockedThenTheClockAndItsUsagesAreRead(): void
    {
        $this->codes->store(self::code(limit: 2));
        $this->use(self::OTHER_ORDER);
        $this->journal->entries = [];

        ($this->redeem)(new RedeemDiscountCode(self::CODE, self::ORDER, 10_000));

        self::assertSame(['find', 'lock', 'clock', 'active uses'], $this->journal->entries);
        self::assertSame(2, $this->usages->activeUsesOf(DiscountCodeId::fromString(self::CODE)));
    }

    public function testTheLastFreeUsageIsTakenAndTheNextOneIsUsedUp(): void
    {
        $this->codes->store(self::code(limit: 1));

        ($this->redeem)(new RedeemDiscountCode(self::CODE, self::ORDER, 10_000));

        $this->assertRejected(DiscountCodeRejectionReason::UsedUp, null, new RedeemDiscountCode(self::CODE, self::OTHER_ORDER, 10_000));
        self::assertNull($this->usageOf(self::OTHER_ORDER));
    }

    public function testADisabledCodeIsInvalidAndNoUsageIsRecorded(): void
    {
        $this->codes->store(self::code(enabled: false));

        $this->assertRejected(DiscountCodeRejectionReason::Invalid, null, new RedeemDiscountCode(self::CODE, self::ORDER, 10_000));
        self::assertNull($this->usageOf(self::ORDER));
    }

    public function testACodeThatEndedByTheClockOfTheCommandIsInvalid(): void
    {
        $this->codes->store(self::code(endsAt: '2026-11-10T12:00:00Z'));

        $this->assertRejected(DiscountCodeRejectionReason::Invalid, null, new RedeemDiscountCode(self::CODE, self::ORDER, 10_000));
    }

    public function testASubtotalBelowTheMinimumIsRefusedWithTheMinimum(): void
    {
        $this->codes->store(self::code(minimum: 30_000));

        $this->assertRejected(DiscountCodeRejectionReason::BelowMinimum, 30_000, new RedeemDiscountCode(self::CODE, self::ORDER, 29_999));

        ($this->redeem)(new RedeemDiscountCode(self::CODE, self::ORDER, 30_000));
        self::assertNotNull($this->usageOf(self::ORDER));
    }

    public function testAnUnknownCodeIsInvalid(): void
    {
        $this->assertRejected(DiscountCodeRejectionReason::Invalid, null, new RedeemDiscountCode(self::CODE, self::ORDER, 10_000));
    }

    public function testTheRejectionCarriesTheTypeAndTheDetailOfPricing(): void
    {
        $this->codes->store(self::code(minimum: 30_000));

        try {
            ($this->redeem)(new RedeemDiscountCode(self::CODE, self::ORDER, 100));
            self::fail('The code must be rejected.');
        } catch (DiscountCodeRejected $rejected) {
            self::assertSame('tag:piotrp321.github.io,2026:shop/problems/discount-code-below-minimum', $rejected->type);
            self::assertSame('Ten kod działa przy zamówieniu od 300,00 zł.', $rejected->getMessage());
        }
    }

    public function testAReleaseMarksTheActiveUsageOfTheOrderReleasedAtNow(): void
    {
        $this->codes->store(self::code(limit: 5));
        $this->use(self::ORDER);
        $this->use(self::OTHER_ORDER);
        $this->journal->entries = [];

        ($this->release)(new ReleaseDiscountCodeUsage(self::ORDER));

        self::assertNotContains('lock', $this->journal->entries, 'the code is not locked');
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->usageOf(self::ORDER)?->releasedAt());
        self::assertTrue($this->usageOf(self::OTHER_ORDER)?->isActive());
        self::assertSame(1, $this->usages->activeUsesOf(DiscountCodeId::fromString(self::CODE)));
    }

    public function testAReleaseOfAnOrderWithoutAUsageChangesNothing(): void
    {
        $this->use(self::OTHER_ORDER);

        ($this->release)(new ReleaseDiscountCodeUsage(self::ORDER));

        self::assertNull($this->usageOf(self::ORDER));
        self::assertSame(0, $this->usages->saves);
    }

    public function testARepeatedReleaseKeepsTheFirstMoment(): void
    {
        $this->use(self::ORDER);
        ($this->release)(new ReleaseDiscountCodeUsage(self::ORDER));
        $this->clock->now = new \DateTimeImmutable('2026-11-10T13:00:00Z');

        ($this->release)(new ReleaseDiscountCodeUsage(self::ORDER));

        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->usageOf(self::ORDER)?->releasedAt());
        self::assertSame(1, $this->usages->saves);
    }

    private function assertRejected(DiscountCodeRejectionReason $reason, ?int $minimum, RedeemDiscountCode $command): void
    {
        try {
            ($this->redeem)($command);
            self::fail('The code must be rejected.');
        } catch (DiscountCodeRejected $rejected) {
            self::assertSame($reason->value, $rejected->reason);
            self::assertSame($minimum, $rejected->minimumOrderGross);
        }
    }

    private function usageOf(string $order): ?DiscountCodeUsage
    {
        return $this->usages->ofOrder(OrderId::fromString($order));
    }

    private function use(string $order): void
    {
        $this->usages->add(DiscountCodeUsage::record(DiscountCodeUsageId::fromString('0192f0c4-7c1a-7c4e-9a6e-'.substr(md5($order), 0, 12)), DiscountCodeId::fromString(self::CODE), OrderId::fromString($order), new \DateTimeImmutable('2026-11-10T11:00:00Z')));
    }

    private static function code(?int $limit = null, bool $enabled = true, ?string $endsAt = null, ?int $minimum = null): DiscountCode
    {
        return DiscountCode::create(
            DiscountCodeId::fromString(self::CODE),
            DiscountCodeValue::of('WITAJ10'),
            DiscountValue::of(DiscountKind::Percent, 10),
            null === $minimum ? null : MinimumOrder::of($minimum),
            ValidityPeriod::of(new \DateTimeImmutable('2026-11-01T00:00:00Z'), null === $endsAt ? null : new \DateTimeImmutable($endsAt)),
            null === $limit ? null : UsageLimit::of($limit),
            $enabled,
            null,
            new \DateTimeImmutable('2026-11-01T00:00:00Z'),
        );
    }
}
