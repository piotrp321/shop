<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Command\CreateDiscountCode;
use App\Pricing\Application\Command\CreateDiscountCodeHandler;
use App\Pricing\Application\Command\ReviseDiscountCode;
use App\Pricing\Application\Command\ReviseDiscountCodeHandler;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeModifiedConcurrently;
use App\Pricing\Domain\DiscountCode\DiscountCodeNotEditable;
use App\Pricing\Domain\DiscountCode\DiscountCodeNotFound;
use App\Pricing\Domain\DiscountCode\DiscountCodeTaken;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageId;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\InvalidDiscountCode;
use App\Pricing\Domain\DiscountCode\OrderId;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryDiscountCodeRepository;
use App\Tests\Unit\Pricing\InMemoryDiscountCodeUsages;
use App\Tests\Unit\Pricing\PricingJournal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CreateDiscountCode and ReviseDiscountCode with in-memory adapters.
 */
final class DiscountCodeCommandsTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6c00';
    private const string OTHER = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6c99';
    private const string NOW = '2026-11-10T12:00:00.400000Z';

    private PricingJournal $journal;
    private InMemoryDiscountCodeRepository $codes;
    private InMemoryDiscountCodeUsages $usages;
    private FixedPricingClock $clock;
    private CreateDiscountCodeHandler $create;
    private ReviseDiscountCodeHandler $revise;

    protected function setUp(): void
    {
        $this->journal = new PricingJournal();
        $this->codes = new InMemoryDiscountCodeRepository($this->journal);
        $this->usages = new InMemoryDiscountCodeUsages($this->journal);
        $this->clock = new FixedPricingClock(self::NOW, $this->journal);
        $this->create = new CreateDiscountCodeHandler($this->codes, $this->clock);
        $this->revise = new ReviseDiscountCodeHandler($this->codes, $this->usages, $this->clock);
    }

    public function testCreateStoresTheCodeOfTheStaffMember(): void
    {
        ($this->create)(self::creation(code: ' minus50 ', kind: DiscountKind::Amount, value: 5000, minimum: 30000, limit: 100));

        $code = $this->codes->find(DiscountCodeId::fromString(self::ID));
        self::assertNotNull($code);
        self::assertSame('MINUS50', $code->code()->toString());
        self::assertTrue($code->value()->equals(DiscountValue::of(DiscountKind::Amount, 5000)));
        self::assertSame(30000, $code->minimumOrder()?->gross());
        self::assertSame(100, $code->usageLimit()?->value());
        self::assertSame('2026-11-01T00:00:00+00:00', $code->period()->startsAt()->format(\DATE_ATOM));
        self::assertNull($code->period()->endsAt());
        self::assertTrue($code->isEnabled());
        self::assertSame(['sub-1', 'Ola'], [$code->createdBy()?->id(), $code->createdBy()?->name()]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $code->createdAt());
    }

    public function testCreateWithoutAnActorIsTheSystem(): void
    {
        ($this->create)(new CreateDiscountCode(self::ID, 'WITAJ10', DiscountKind::Percent, 10, null, new \DateTimeImmutable(self::NOW), null, null, true, null, null));

        self::assertNull($this->codes->find(DiscountCodeId::fromString(self::ID))?->createdBy());
    }

    /**
     * @return iterable<string, array{CreateDiscountCode, string}>
     */
    public static function invalidCreations(): iterable
    {
        yield 'a code of two characters' => [self::creation(code: 'AB'), InvalidDiscountCode::CODE];
        yield 'a percent of 91' => [self::creation(value: 91), InvalidDiscountCode::VALUE];
        yield 'an amount of 0,99 zł' => [self::creation(kind: DiscountKind::Amount, value: 99, minimum: 30000), InvalidDiscountCode::VALUE];
        yield 'an amount without a minimum' => [self::creation(kind: DiscountKind::Amount, value: 5000), InvalidDiscountCode::MINIMUM_ORDER];
        yield 'a minimum of 0' => [self::creation(minimum: 0), InvalidDiscountCode::MINIMUM_ORDER];
        yield 'an end at the start' => [self::creation(endsAt: '2026-11-01T00:00:00Z'), InvalidDiscountCode::ENDS_AT];
        yield 'a limit of 0' => [self::creation(limit: 0), InvalidDiscountCode::USAGE_LIMIT];
    }

    #[DataProvider('invalidCreations')]
    public function testCreateRefusesAnInvalidCodeAtItsField(CreateDiscountCode $command, string $field): void
    {
        try {
            ($this->create)($command);
            self::fail('The code must be refused.');
        } catch (InvalidDiscountCode $refused) {
            self::assertSame($field, $refused->field);
        }
        self::assertSame(0, $this->codes->writes);
    }

    public function testCreateOfATakenTextInAnotherCaseIsRefused(): void
    {
        $this->codes->store($this->stored(self::OTHER, 'WITAJ10', '2026-11-01T00:00:00Z', null, null));

        $this->expectException(DiscountCodeTaken::class);
        ($this->create)(self::creation(code: 'witaj10'));
    }

    public function testReviseLocksTheCodeThenReadsTheClockAndTheUsesThenSaves(): void
    {
        $this->codes->store($this->stored(self::ID, 'WITAJ10', '2026-11-01T00:00:00Z', null, 10));
        $this->use(3);

        ($this->revise)($this->revision(limit: 5, enabled: false));

        self::assertSame(['lock', 'clock', 'active uses', 'save'], $this->journal->entries);
        $code = $this->codes->find(DiscountCodeId::fromString(self::ID));
        self::assertNotNull($code);
        self::assertSame(5, $code->usageLimit()?->value());
        self::assertFalse($code->isEnabled());
        self::assertSame(2, $code->version());
        self::assertSame(['sub-2', 'Bartek'], [$code->updatedBy()?->id(), $code->updatedBy()?->name()]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $code->updatedAt());
    }

    public function testReviseRefusesALimitBelowTheActiveUsesReadUnderTheLock(): void
    {
        $this->codes->store($this->stored(self::ID, 'WITAJ10', '2026-11-01T00:00:00Z', null, 10));
        $this->use(4);

        try {
            ($this->revise)($this->revision(limit: 3));
            self::fail('The limit must be refused.');
        } catch (InvalidDiscountCode $refused) {
            self::assertSame('Limit nie może być mniejszy od liczby użyć (4).', $refused->getMessage());
        }
        self::assertSame(1, $this->codes->find(DiscountCodeId::fromString(self::ID))?->version());
    }

    public function testReviseDoesNotAddALimitToAnActiveCodeWithoutOne(): void
    {
        $this->codes->store($this->stored(self::ID, 'WITAJ10', '2026-11-01T00:00:00Z', null, null));

        $this->expectException(DiscountCodeNotEditable::class);
        ($this->revise)($this->revision(limit: 100));
    }

    public function testReviseOfAPlannedCodeChangesEverything(): void
    {
        $this->codes->store($this->stored(self::ID, 'WITAJ10', '2026-11-15T00:00:00Z', null, null));

        ($this->revise)($this->revision(kind: DiscountKind::Amount, value: 2000, minimum: 10000, startsAt: '2026-11-16T00:00:00Z', endsAt: '2026-11-30T00:00:00Z', limit: 5));

        $code = $this->codes->find(DiscountCodeId::fromString(self::ID));
        self::assertNotNull($code);
        self::assertTrue($code->value()->equals(DiscountValue::of(DiscountKind::Amount, 2000)));
        self::assertSame('2026-11-30T00:00:00+00:00', $code->period()->endsAt()?->format(\DATE_ATOM));
        self::assertSame(5, $code->usageLimit()?->value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield 'an unknown code' => [self::OTHER];
        yield 'an id that is no UUID' => ['code-1'];
    }

    #[DataProvider('unknownIds')]
    public function testReviseOfAnUnknownCodeIsNotFound(string $id): void
    {
        $this->codes->store($this->stored(self::ID, 'WITAJ10', '2026-11-01T00:00:00Z', null, null));

        $this->expectException(DiscountCodeNotFound::class);
        ($this->revise)($this->revision(id: $id));
    }

    public function testReviseOfAnotherVersionIsAConflict(): void
    {
        $this->codes->store($this->stored(self::ID, 'WITAJ10', '2026-11-01T00:00:00Z', null, null));

        try {
            ($this->revise)($this->revision(expectedVersion: 2, enabled: false));
            self::fail('The revise must be refused.');
        } catch (DiscountCodeModifiedConcurrently) {
        }
        self::assertTrue($this->codes->find(DiscountCodeId::fromString(self::ID))?->isEnabled());
    }

    public function testReviseRefusesAnInvalidValueBeforeTheVersion(): void
    {
        $this->codes->store($this->stored(self::ID, 'WITAJ10', '2026-11-01T00:00:00Z', null, null));

        $this->expectExceptionObject(InvalidDiscountCode::percent());
        ($this->revise)($this->revision(expectedVersion: 2, value: 0));
    }

    public function testReviseRefusesAChangedEndNotAfterNow(): void
    {
        $this->codes->store($this->stored(self::ID, 'WITAJ10', '2026-11-01T00:00:00Z', null, null));

        $this->expectExceptionObject(InvalidDiscountCode::endInThePast());
        ($this->revise)($this->revision(endsAt: '2026-11-10T12:00:00Z'));
    }

    private function use(int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->usages->add(DiscountCodeUsage::record(
                DiscountCodeUsageId::fromString(\sprintf('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6d%02d', $i)),
                DiscountCodeId::fromString(self::ID),
                OrderId::fromString(\sprintf('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6e%02d', $i)),
                new \DateTimeImmutable('2026-11-05T00:00:00Z'),
            ));
        }
    }

    private function stored(string $id, string $code, string $startsAt, ?string $endsAt, ?int $limit): DiscountCode
    {
        return DiscountCode::create(
            DiscountCodeId::fromString($id),
            DiscountCodeValue::of($code),
            DiscountValue::of(DiscountKind::Percent, 10),
            null,
            ValidityPeriod::of(new \DateTimeImmutable($startsAt), null === $endsAt ? null : new \DateTimeImmutable($endsAt)),
            null === $limit ? null : UsageLimit::of($limit),
            true,
            null,
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
    }

    private static function creation(
        string $code = 'WITAJ10',
        DiscountKind $kind = DiscountKind::Percent,
        int $value = 10,
        ?int $minimum = null,
        string $startsAt = '2026-11-01T00:00:00Z',
        ?string $endsAt = null,
        ?int $limit = null,
    ): CreateDiscountCode {
        return new CreateDiscountCode(self::ID, $code, $kind, $value, $minimum, new \DateTimeImmutable($startsAt), null === $endsAt ? null : new \DateTimeImmutable($endsAt), $limit, true, 'sub-1', 'Ola');
    }

    private function revision(
        string $id = self::ID,
        int $expectedVersion = 1,
        DiscountKind $kind = DiscountKind::Percent,
        int $value = 10,
        ?int $minimum = null,
        string $startsAt = '2026-11-01T00:00:00Z',
        ?string $endsAt = null,
        ?int $limit = null,
        bool $enabled = true,
    ): ReviseDiscountCode {
        return new ReviseDiscountCode($id, $expectedVersion, $kind, $value, $minimum, new \DateTimeImmutable($startsAt), null === $endsAt ? null : new \DateTimeImmutable($endsAt), $limit, $enabled, 'sub-2', 'Bartek');
    }
}
