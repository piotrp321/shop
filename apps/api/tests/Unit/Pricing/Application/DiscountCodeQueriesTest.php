<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Port\ListedDiscountCode;
use App\Pricing\Application\Query\DiscountCodeCursor;
use App\Pricing\Application\Query\DiscountCodeList;
use App\Pricing\Application\Query\DiscountCodeView;
use App\Pricing\Application\Query\GetDiscountCode;
use App\Pricing\Application\Query\GetDiscountCodeHandler;
use App\Pricing\Application\Query\InvalidDiscountCodeCursor;
use App\Pricing\Application\Query\ListDiscountCodes;
use App\Pricing\Application\Query\ListDiscountCodesHandler;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeNotFound;
use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;
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
use App\Tests\Unit\Pricing\InMemoryDiscountCodeReader;
use App\Tests\Unit\Pricing\InMemoryDiscountCodeRepository;
use App\Tests\Unit\Pricing\InMemoryDiscountCodeUsages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ListDiscountCodes and GetDiscountCode with in-memory adapters.
 */
final class DiscountCodeQueriesTest extends TestCase
{
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7a01';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7a02';
    private const string C = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7a03';
    private const string NOW = '2026-11-10T12:00:00Z';

    private FixedPricingClock $clock;
    private InMemoryDiscountCodeReader $reader;
    private InMemoryDiscountCodeRepository $codes;
    private InMemoryDiscountCodeUsages $usages;

    protected function setUp(): void
    {
        $this->clock = new FixedPricingClock(self::NOW);
        $this->reader = new InMemoryDiscountCodeReader();
        $this->codes = new InMemoryDiscountCodeRepository();
        $this->usages = new InMemoryDiscountCodeUsages();
    }

    public function testTheListPagesNewestFirstWithTheStatusAtNowAndTheActiveUses(): void
    {
        $this->reader->codes = [
            $this->listed(self::A, 'LATO15', '2026-11-01T00:00:00Z', '2026-10-01T00:00:00Z', '2026-11-01T00:00:00Z', true, 0),
            $this->listed(self::B, 'WITAJ10', '2026-11-03T00:00:00Z', '2026-11-03T00:00:00Z', null, true, 7),
            $this->listed(self::C, 'ZIMA20', '2026-11-05T00:00:00Z', '2026-12-01T00:00:00Z', null, false, 0),
        ];

        $first = $this->list(new ListDiscountCodes(null, null, null, 2));

        self::assertSame([self::C, self::B], array_map(static fn (DiscountCodeView $code): string => $code->id, $first->items));
        self::assertSame([DiscountCodeStatus::Disabled, DiscountCodeStatus::Active], array_map(static fn (DiscountCodeView $code): DiscountCodeStatus => $code->status, $first->items));
        self::assertSame(7, $first->items[1]->activeUses);
        self::assertNotNull($first->next);
        self::assertSame(3, $this->reader->pages[0][4], 'one more than the page');
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->reader->pages[0][2]);

        $second = $this->list(new ListDiscountCodes(null, null, $first->next, 2));

        self::assertSame([self::A], array_map(static fn (DiscountCodeView $code): string => $code->id, $second->items));
        self::assertSame(DiscountCodeStatus::Expired, $second->items[0]->status);
        self::assertNull($second->next);
    }

    public function testTheListPassesTheStatusAndTheCodePrefixInUpperCase(): void
    {
        $this->reader->codes = [
            $this->listed(self::A, 'WITAJ10', '2026-11-01T00:00:00Z', '2026-11-01T00:00:00Z', null, true, 0),
            $this->listed(self::B, 'WIOSNA', '2026-11-02T00:00:00Z', '2026-11-01T00:00:00Z', null, true, 0),
        ];

        $list = $this->list(new ListDiscountCodes(DiscountCodeStatus::Active, " wit\u{A0}", null, 25));

        self::assertSame([self::A], array_map(static fn (DiscountCodeView $code): string => $code->id, $list->items));
        self::assertSame(DiscountCodeStatus::Active, $this->reader->pages[0][0]);
        self::assertSame('WIT', $this->reader->pages[0][1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prefixesNoCodeHas(): iterable
    {
        yield 'a percent sign' => ['10%'];
        yield 'an underscore' => ['A_B'];
        yield 'a Polish letter' => ['ŁÓDŹ'];
        yield 'a NUL byte' => ["A\0"];
        yield 'only no-break spaces' => ["\u{A0}\u{A0}"];
        yield 'longer than a code' => [str_repeat('A', 33)];
    }

    #[DataProvider('prefixesNoCodeHas')]
    public function testAPrefixNoCodeCanHaveGivesAnEmptyPageWithoutARead(string $prefix): void
    {
        $this->reader->codes = [$this->listed(self::A, 'WITAJ10', '2026-11-01T00:00:00Z', '2026-11-01T00:00:00Z', null, true, 0)];

        $list = $this->list(new ListDiscountCodes(null, $prefix, null, 25));

        self::assertSame([], $list->items);
        self::assertNull($list->next);
        self::assertSame([], $this->reader->pages);
    }

    public function testACursorNobodyIssuedIsRefused(): void
    {
        $this->expectException(InvalidDiscountCodeCursor::class);
        $this->list(new ListDiscountCodes(null, null, 'garbage', 25));
    }

    public function testACodeIsReadWithItsStatusAndActiveUses(): void
    {
        $this->codes->store(DiscountCode::create(
            DiscountCodeId::fromString(self::A),
            DiscountCodeValue::of('MINUS50'),
            DiscountValue::of(DiscountKind::Amount, 5000),
            MinimumOrder::of(30000),
            ValidityPeriod::of(new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-12-01T00:00:00Z')),
            UsageLimit::of(10),
            true,
            null,
            new \DateTimeImmutable('2026-10-30T10:00:00Z'),
        ));
        foreach (['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7b01', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7b02'] as $i => $order) {
            $this->usages->add(DiscountCodeUsage::record(DiscountCodeUsageId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7c0'.$i), DiscountCodeId::fromString(self::A), OrderId::fromString($order), new \DateTimeImmutable('2026-11-05T00:00:00Z')));
        }
        $this->usages->add(DiscountCodeUsage::reconstitute(DiscountCodeUsageId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7c09'), DiscountCodeId::fromString(self::A), OrderId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7b09'), new \DateTimeImmutable('2026-11-05T00:00:00Z'), new \DateTimeImmutable('2026-11-05T00:35:00Z')));

        $code = new GetDiscountCodeHandler($this->codes, $this->usages, $this->clock)(new GetDiscountCode(strtoupper(self::A)));

        self::assertEquals(
            new DiscountCodeView(
                self::A, 'MINUS50', DiscountKind::Amount, 5000, 30000,
                new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-12-01T00:00:00Z'),
                10, 2, true, DiscountCodeStatus::Active, 1,
            ),
            $code,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield 'an unknown code' => [self::B];
        yield 'an id that is no UUID' => ['code-1'];
    }

    #[DataProvider('unknownIds')]
    public function testAnUnknownCodeIsNotFound(string $id): void
    {
        $this->expectException(DiscountCodeNotFound::class);
        new GetDiscountCodeHandler($this->codes, $this->usages, $this->clock)(new GetDiscountCode($id));
    }

    public function testTheCursorRoundTripsAndRefusesOtherText(): void
    {
        $listed = $this->listed(self::A, 'WITAJ10', '2026-11-01T10:00:00Z', '2026-11-01T00:00:00Z', null, true, 0);

        $decoded = DiscountCodeCursor::decode(DiscountCodeCursor::after($listed)->encode());

        self::assertEquals(new \DateTimeImmutable('2026-11-01T10:00:00Z'), $decoded->createdAt);
        self::assertSame(self::A, $decoded->id);
        foreach (['', 'garbage', '%%%', rtrim(strtr(base64_encode('{"t":"2026-02-31T00:00:00+00:00","i":"'.self::A.'"}'), '+/', '-_'), '='), rtrim(strtr(base64_encode('{"t":"2026-11-01T10:00:00+01:00","i":"'.self::A.'"}'), '+/', '-_'), '='), rtrim(strtr(base64_encode('{"t":"2026-11-01T10:00:00+00:00","i":"x"}'), '+/', '-_'), '=')] as $text) {
            try {
                DiscountCodeCursor::decode($text);
                self::fail('Not a cursor: '.$text);
            } catch (InvalidDiscountCodeCursor) {
            }
        }
    }

    private function list(ListDiscountCodes $query): DiscountCodeList
    {
        return new ListDiscountCodesHandler($this->reader, $this->clock)($query);
    }

    private function listed(string $id, string $code, string $createdAt, string $startsAt, ?string $endsAt, bool $enabled, int $activeUses): ListedDiscountCode
    {
        return new ListedDiscountCode(
            $id,
            $code,
            DiscountKind::Percent,
            10,
            null,
            new \DateTimeImmutable($startsAt),
            null === $endsAt ? null : new \DateTimeImmutable($endsAt),
            null,
            $enabled,
            $activeUses,
            1,
            new \DateTimeImmutable($createdAt),
        );
    }
}
