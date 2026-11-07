<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Port\ListedPromotion;
use App\Pricing\Application\Query\InvalidPromotionCursor;
use App\Pricing\Application\Query\PromotionCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromotionCursorTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    public function testEncodesToOpaqueUrlSafeTextThatDecodesToTheSamePosition(): void
    {
        $promotion = new ListedPromotion(self::ID, 'Jesień', 20, new \DateTimeImmutable('2026-10-31 12:30:45+02:00'), new \DateTimeImmutable('2026-11-30 12:30:45+02:00'), 3, 1);

        $text = PromotionCursor::after($promotion)->encode();
        $decoded = PromotionCursor::decode($text);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $text);
        self::assertSame(self::ID, $decoded->id);
        self::assertSame('2026-10-31T10:30:45+00:00', $decoded->startsAt->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCursors(): iterable
    {
        yield 'empty' => [''];
        yield 'not base64url' => ['garbage!'];
        yield 'not json' => [self::base64('hello')];
        yield 'a list' => [self::base64('["2026-10-31T10:30:45+00:00","'.self::ID.'"]')];
        yield 'missing id' => [self::base64('{"t":"2026-10-31T10:30:45+00:00"}')];
        yield 'extra key' => [self::base64('{"t":"2026-10-31T10:30:45+00:00","i":"'.self::ID.'","x":1}')];
        yield 'time not a string' => [self::base64('{"t":1,"i":"'.self::ID.'"}')];
        yield 'time of another format' => [self::base64('{"t":"yesterday","i":"'.self::ID.'"}')];
        yield 'time with a fraction' => [self::base64('{"t":"2026-10-31T10:30:45.5+00:00","i":"'.self::ID.'"}')];
        yield 'impossible date' => [self::base64('{"t":"2026-02-31T10:30:45+00:00","i":"'.self::ID.'"}')];
        yield 'year zero' => [self::base64('{"t":"0000-01-01T00:00:00+00:00","i":"'.self::ID.'"}')];
        yield 'NUL byte inside the time' => [self::base64('{"t":"2026-10-31T10:30:\u000045+00:00","i":"'.self::ID.'"}')];
        yield 'time not in UTC' => [self::base64('{"t":"2026-10-31T10:30:45+01:00","i":"'.self::ID.'"}')];
        yield 'id not a uuid' => [self::base64('{"t":"2026-10-31T10:30:45+00:00","i":"abc"}')];
        yield 'id in upper case' => [self::base64('{"t":"2026-10-31T10:30:45+00:00","i":"'.strtoupper(self::ID).'"}')];
        yield 'too long' => [str_repeat('a', 3000)];
    }

    #[DataProvider('invalidCursors')]
    public function testRejectsWhatWasNeverHandedOut(string $text): void
    {
        $this->expectException(InvalidPromotionCursor::class);

        PromotionCursor::decode($text);
    }

    private static function base64(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
