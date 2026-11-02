<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Query\InvalidStockHistoryCursor;
use App\Inventory\Application\Query\StockHistoryCursor;
use App\Inventory\Application\Query\StockHistoryItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockHistoryCursorTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    public function testEncodesToOpaqueUrlSafeTextThatDecodesToTheSamePosition(): void
    {
        $item = new StockHistoryItem(self::ID, new \DateTimeImmutable('2026-10-31 12:30:45.123456+02:00'), 'receipt', 5, 0, 5, 0, null, null, 'key-1', 'staff', 'Jan');

        $text = StockHistoryCursor::after($item)->encode();
        $decoded = StockHistoryCursor::decode($text);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $text);
        self::assertSame(self::ID, $decoded->id);
        self::assertEquals($item->occurredAt, $decoded->occurredAt);
        self::assertSame('2026-10-31T10:30:45.123456+00:00', $decoded->occurredAt->format('Y-m-d\TH:i:s.uP'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCursors(): iterable
    {
        yield 'not base64url' => ['garbage!'];
        yield 'not json' => [self::base64('hello')];
        yield 'a list' => [self::base64('["2026-10-31T10:30:45.000000+00:00","'.self::ID.'"]')];
        yield 'missing id' => [self::base64('{"t":"2026-10-31T10:30:45.000000+00:00"}')];
        yield 'extra key' => [self::base64('{"t":"2026-10-31T10:30:45.000000+00:00","i":"'.self::ID.'","x":1}')];
        yield 'time not a string' => [self::base64('{"t":1,"i":"'.self::ID.'"}')];
        yield 'time of another format' => [self::base64('{"t":"yesterday","i":"'.self::ID.'"}')];
        yield 'impossible date' => [self::base64('{"t":"2026-02-31T10:30:45.000000+00:00","i":"'.self::ID.'"}')];
        yield 'year zero' => [self::base64('{"t":"0000-01-01T00:00:00.000000+00:00","i":"'.self::ID.'"}')];
        yield 'time with a NUL byte' => [self::base64('{"t":"2026-10-31T10:30:45.000000+00:00\u0000","i":"'.self::ID.'"}')];
        yield 'NUL byte inside the time' => [self::base64('{"t":"2026-10-31T10:30:\u000045.000000+00:00","i":"'.self::ID.'"}')];
        yield 'time not in UTC, offset out of range' => [self::base64('{"t":"2026-10-31T10:30:45.000000+16:00","i":"'.self::ID.'"}')];
        yield 'time not in UTC, offset +01:00' => [self::base64('{"t":"2026-10-31T10:30:45.000000+01:00","i":"'.self::ID.'"}')];
        yield 'time not in UTC, offset -23:59' => [self::base64('{"t":"2026-10-31T10:30:45.000000-23:59","i":"'.self::ID.'"}')];
        yield 'time not in UTC, offset +99:59' => [self::base64('{"t":"2026-10-31T10:30:45.000000+99:59","i":"'.self::ID.'"}')];
        yield 'time with a negative zero offset' => [self::base64('{"t":"2026-10-31T10:30:45.000000-00:00","i":"'.self::ID.'"}')];
        yield 'id not a uuid' => [self::base64('{"t":"2026-10-31T10:30:45.000000+00:00","i":"abc"}')];
        yield 'too long' => [str_repeat('a', 3000)];
    }

    #[DataProvider('invalidCursors')]
    public function testRejectsWhatWasNeverHandedOut(string $text): void
    {
        $this->expectException(InvalidStockHistoryCursor::class);

        StockHistoryCursor::decode($text);
    }

    public function testTheEarliestTimePostgreSqlAcceptsIsStillACursor(): void
    {
        $decoded = StockHistoryCursor::decode(self::base64('{"t":"0001-01-01T00:00:00.000000+00:00","i":"'.self::ID.'"}'));

        self::assertSame('0001-01-01T00:00:00.000000+00:00', $decoded->occurredAt->format('Y-m-d\TH:i:s.uP'));
    }

    private static function base64(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
