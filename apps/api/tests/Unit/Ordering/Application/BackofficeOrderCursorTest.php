<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Ordering\Application\Query\InvalidBackofficeOrderCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The keyset cursor of the order list of the panel: opaque base64url of the sort, a moment and an id; only a cursor
 * the API could have handed out for the same sort is one.
 */
final class BackofficeOrderCursorTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-0000000000aa';

    public function testTheCursorAfterAnOrderKeepsItsPlacementAndId(): void
    {
        $text = BackofficeOrderCursor::after(self::order(), false)->encode();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $text, 'base64url without padding');
        $cursor = BackofficeOrderCursor::decode($text, false);
        self::assertFalse($cursor->byRefundDueDate);
        self::assertSame(self::ID, $cursor->id);
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:00:00.123456+00:00'), $cursor->at, 'the whole precision, in UTC');
    }

    public function testTheCursorOfThePendingRefundsKeepsTheDueDate(): void
    {
        $cursor = BackofficeOrderCursor::decode(BackofficeOrderCursor::after(self::order(), true)->encode(), true);

        self::assertTrue($cursor->byRefundDueDate);
        self::assertEquals(new \DateTimeImmutable('2026-11-19 09:00:00+00:00'), $cursor->at);
    }

    public function testThePendingRefundsNeedAnOrderWithARefund(): void
    {
        $this->expectException(\LogicException::class);

        BackofficeOrderCursor::after(self::order(refundDueAt: null), true);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function invalidCursors(): iterable
    {
        $encode = static fn (mixed $data): string => rtrim(strtr(base64_encode(json_encode($data, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $valid = ['s' => 'placed', 't' => '2026-11-05T12:00:00.000000+00:00', 'i' => self::ID];

        yield 'empty' => ['', false];
        yield 'not base64url' => ['a+b/c=', false];
        yield 'not JSON' => [rtrim(strtr(base64_encode('{"s":'), '+/', '-_'), '='), false];
        yield 'a JSON string' => [$encode('placed'), false];
        yield 'nested too deep' => [$encode(['s' => 'placed', 't' => ['2026'], 'i' => self::ID]), false];
        yield 'without the sort' => [$encode(['t' => $valid['t'], 'i' => self::ID]), false];
        yield 'an unknown sort' => [$encode(['s' => 'number'] + $valid), false];
        yield 'the other sort' => [$encode($valid), true];
        yield 'an extra key' => [$encode($valid + ['x' => 1]), false];
        yield 'a time without microseconds' => [$encode(['t' => '2026-11-05T12:00:00+00:00'] + $valid), false];
        yield 'a time in another offset' => [$encode(['t' => '2026-11-05T13:00:00.000000+01:00'] + $valid), false];
        yield 'an impossible date' => [$encode(['t' => '2026-02-31T12:00:00.000000+00:00'] + $valid), false];
        yield 'the year 0' => [$encode(['t' => '0000-01-01T00:00:00.000000+00:00'] + $valid), false];
        yield 'a time with a NUL' => [$encode(['t' => "2026-11-05T12:00:00.000000+00:00\0"] + $valid), false];
        yield 'an id that is no UUID' => [$encode(['i' => 'R-100001'] + $valid), false];
        yield 'an id in upper case' => [$encode(['i' => strtoupper(self::ID)] + $valid), false];
        yield 'an integer id' => [$encode(['i' => 1] + $valid), false];
        yield 'too long' => [str_repeat('A', 2049), false];
    }

    #[DataProvider('invalidCursors')]
    public function testOnlyACursorOfTheSameSortHandedOutIsOne(string $text, bool $byRefundDueDate): void
    {
        $this->expectException(InvalidBackofficeOrderCursor::class);

        BackofficeOrderCursor::decode($text, $byRefundDueDate);
    }

    public function testTheMessageNeverContainsTheCursor(): void
    {
        try {
            BackofficeOrderCursor::decode('garbage', false);
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
