<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Query\InvalidPriceHistoryCursor;
use App\Pricing\Application\Query\PriceHistoryCursor;
use App\Pricing\Application\Query\PriceHistoryItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceHistoryCursorTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    public function testEncodesToOpaqueUrlSafeTextThatDecodesToTheSamePosition(): void
    {
        $item = new PriceHistoryItem(self::ID, new \DateTimeImmutable('2026-10-31 12:30:45.123456+02:00'), 100, 90, 10, 1000, 'standard', 'staff', 'Jan');

        $text = PriceHistoryCursor::after($item)->encode();
        $decoded = PriceHistoryCursor::decode($text);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $text);
        self::assertSame(self::ID, $decoded->id);
        self::assertEquals($item->changedAt, $decoded->changedAt);
        self::assertSame('2026-10-31T10:30:45.123456+00:00', $decoded->changedAt->format('Y-m-d\TH:i:s.uP'));
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
        yield 'id not a uuid' => [self::base64('{"t":"2026-10-31T10:30:45.000000+00:00","i":"abc"}')];
        yield 'too long' => [str_repeat('a', 3000)];
    }

    #[DataProvider('invalidCursors')]
    public function testRejectsWhatWasNeverHandedOut(string $text): void
    {
        $this->expectException(InvalidPriceHistoryCursor::class);

        PriceHistoryCursor::decode($text);
    }

    public function testTheEarliestTimePostgreSqlAcceptsIsStillACursor(): void
    {
        $decoded = PriceHistoryCursor::decode(self::base64('{"t":"0001-01-01T00:00:00.000000+00:00","i":"'.self::ID.'"}'));

        self::assertSame('0001-01-01T00:00:00.000000+00:00', $decoded->changedAt->format('Y-m-d\TH:i:s.uP'));
    }

    private static function base64(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
