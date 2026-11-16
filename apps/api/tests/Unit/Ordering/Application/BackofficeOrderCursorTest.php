<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Ordering\Application\Query\BackofficeOrderSort;
use App\Ordering\Application\Query\InvalidBackofficeOrderCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The keyset cursor of the order list of the panel: opaque base64url of the sort, a moment or an amount and an id; only
 * a cursor the API could have handed out for the same sort is one.
 */
final class BackofficeOrderCursorTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-0000000000aa';

    public function testTheCursorAfterAnOrderKeepsItsPlacementAndId(): void
    {
        $text = BackofficeOrderCursor::after(self::order(), BackofficeOrderSort::PlacedDescending)->encode();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $text, 'base64url without padding');
        $cursor = BackofficeOrderCursor::decode($text, BackofficeOrderSort::PlacedDescending);
        self::assertSame(BackofficeOrderSort::PlacedDescending, $cursor->sort);
        self::assertSame(self::ID, $cursor->id);
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:00:00.123456+00:00'), $cursor->value, 'the whole precision, in UTC');
    }

    public function testTheCursorOfThePendingRefundsKeepsTheDueDate(): void
    {
        $cursor = BackofficeOrderCursor::decode(BackofficeOrderCursor::after(self::order(), BackofficeOrderSort::RefundDueDate)->encode(), BackofficeOrderSort::RefundDueDate);

        self::assertSame(BackofficeOrderSort::RefundDueDate, $cursor->sort);
        self::assertEquals(new \DateTimeImmutable('2026-11-19 09:00:00+00:00'), $cursor->value);
    }

    /**
     * @return iterable<string, array{BackofficeOrderSort, \DateTimeImmutable|int}>
     */
    public static function sortsAndPositions(): iterable
    {
        yield 'newest first' => [BackofficeOrderSort::PlacedDescending, new \DateTimeImmutable('2026-11-05 12:00:00.123456+00:00')];
        yield 'oldest first' => [BackofficeOrderSort::PlacedAscending, new \DateTimeImmutable('2026-11-05 12:00:00.123456+00:00')];
        yield 'highest amount first' => [BackofficeOrderSort::TotalDescending, 26_600];
        yield 'lowest amount first' => [BackofficeOrderSort::TotalAscending, 26_600];
    }

    #[DataProvider('sortsAndPositions')]
    public function testEverySortKeepsItsPositionAndItsKey(BackofficeOrderSort $sort, \DateTimeImmutable|int $position): void
    {
        $cursor = BackofficeOrderCursor::decode(BackofficeOrderCursor::after(self::order(), $sort)->encode(), $sort);

        self::assertSame($sort, $cursor->sort);
        self::assertEquals($position, $cursor->value);
        self::assertSame(self::ID, $cursor->id);
    }

    /**
     * @return iterable<string, array{BackofficeOrderSort, BackofficeOrderSort}>
     */
    public static function pairsOfDifferentSorts(): iterable
    {
        foreach (BackofficeOrderSort::cases() as $issued) {
            foreach (BackofficeOrderSort::cases() as $used) {
                if ($issued !== $used) {
                    yield $issued->value.' used as '.$used->value => [$issued, $used];
                }
            }
        }
    }

    #[DataProvider('pairsOfDifferentSorts')]
    public function testACursorOfAnotherSortIsRefused(BackofficeOrderSort $issued, BackofficeOrderSort $used): void
    {
        $text = BackofficeOrderCursor::after(self::order(), $issued)->encode();

        $this->expectException(InvalidBackofficeOrderCursor::class);

        BackofficeOrderCursor::decode($text, $used);
    }

    public function testTheCursorOfTheHistoryOfACustomerIsTheOneOfTheNewestFirst(): void
    {
        $text = BackofficeOrderCursor::placedAfter(new \DateTimeImmutable('2026-11-05 13:00:00.123456+01:00'), self::ID)->encode();

        $cursor = BackofficeOrderCursor::decode($text, BackofficeOrderSort::PlacedDescending);

        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:00:00.123456+00:00'), $cursor->value);
        self::assertSame($text, BackofficeOrderCursor::after(self::order(), BackofficeOrderSort::PlacedDescending)->encode());
    }

    public function testThePendingRefundsNeedAnOrderWithARefund(): void
    {
        $this->expectException(\LogicException::class);

        BackofficeOrderCursor::after(self::order(refundDueAt: null), BackofficeOrderSort::RefundDueDate);
    }

    /**
     * @return iterable<string, array{string, BackofficeOrderSort}>
     */
    public static function invalidCursors(): iterable
    {
        $encode = static fn (mixed $data): string => rtrim(strtr(base64_encode(json_encode($data, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $valid = ['s' => '-placedAt', 'v' => '2026-11-05T12:00:00.000000+00:00', 'i' => self::ID];
        $placed = BackofficeOrderSort::PlacedDescending;
        $byTotal = ['s' => '-total', 'v' => 26_600, 'i' => self::ID];

        yield 'empty' => ['', $placed];
        yield 'not base64url' => ['a+b/c=', $placed];
        yield 'not JSON' => [rtrim(strtr(base64_encode('{"s":'), '+/', '-_'), '='), $placed];
        yield 'a JSON string' => [$encode('placed'), $placed];
        yield 'nested too deep' => [$encode(['s' => '-placedAt', 'v' => ['2026'], 'i' => self::ID]), $placed];
        yield 'without the sort' => [$encode(['v' => $valid['v'], 'i' => self::ID]), $placed];
        yield 'an unknown sort' => [$encode(['s' => 'number'] + $valid), $placed];
        yield 'the old key of the sort' => [$encode(['s' => 'placed'] + $valid), $placed];
        yield 'the other sort' => [$encode($valid), BackofficeOrderSort::RefundDueDate];
        yield 'an extra key' => [$encode($valid + ['x' => 1]), $placed];
        yield 'the old key of the moment' => [$encode(['t' => $valid['v'], 's' => $valid['s'], 'i' => self::ID]), $placed];
        yield 'a time without microseconds' => [$encode(['v' => '2026-11-05T12:00:00+00:00'] + $valid), $placed];
        yield 'a time in another offset' => [$encode(['v' => '2026-11-05T13:00:00.000000+01:00'] + $valid), $placed];
        yield 'an impossible date' => [$encode(['v' => '2026-02-31T12:00:00.000000+00:00'] + $valid), $placed];
        yield 'the year 0' => [$encode(['v' => '0000-01-01T00:00:00.000000+00:00'] + $valid), $placed];
        yield 'a time with a NUL' => [$encode(['v' => "2026-11-05T12:00:00.000000+00:00\0"] + $valid), $placed];
        yield 'an amount as a moment' => [$encode(['v' => 26_600] + $valid), $placed];
        yield 'a moment as an amount' => [$encode(['v' => '2026-11-05T12:00:00.000000+00:00'] + $byTotal), BackofficeOrderSort::TotalDescending];
        yield 'an amount as text' => [$encode(['v' => '26600'] + $byTotal), BackofficeOrderSort::TotalDescending];
        yield 'a fractional amount' => [$encode(['v' => 266.5] + $byTotal), BackofficeOrderSort::TotalDescending];
        yield 'a negative amount' => [$encode(['v' => -1] + $byTotal), BackofficeOrderSort::TotalDescending];
        yield 'an id that is no UUID' => [$encode(['i' => 'R-100001'] + $valid), $placed];
        yield 'an id in upper case' => [$encode(['i' => strtoupper(self::ID)] + $valid), $placed];
        yield 'an integer id' => [$encode(['i' => 1] + $valid), $placed];
        yield 'too long' => [str_repeat('A', 2049), $placed];
    }

    #[DataProvider('invalidCursors')]
    public function testOnlyACursorOfTheSameSortHandedOutIsOne(string $text, BackofficeOrderSort $sort): void
    {
        $this->expectException(InvalidBackofficeOrderCursor::class);

        BackofficeOrderCursor::decode($text, $sort);
    }

    public function testTheMessageNeverContainsTheCursor(): void
    {
        try {
            BackofficeOrderCursor::decode('garbage', BackofficeOrderSort::PlacedDescending);
            self::fail('Expected an invalid cursor.');
        } catch (InvalidBackofficeOrderCursor $invalid) {
            self::assertSame('The paging cursor is invalid.', $invalid->getMessage());
        }
    }

    private static function order(?string $refundDueAt = '2026-11-19 10:00:00+01:00'): ListedOrder
    {
        return new ListedOrder(
            self::ID,
            'R-100001',
            new \DateTimeImmutable('2026-11-05 13:00:00.123456+01:00'),
            'cancelled',
            'jan@example.com',
            'Jan Kowalski',
            26_600,
            'PLN',
            3,
            null === $refundDueAt ? null : 26_600,
            null === $refundDueAt ? null : new \DateTimeImmutable($refundDueAt),
            null,
        );
    }
}
