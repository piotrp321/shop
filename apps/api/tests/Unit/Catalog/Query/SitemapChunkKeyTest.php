<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Query;

use App\Catalog\Application\Query\InvalidSitemapChunk;
use App\Catalog\Application\Query\SitemapChunkKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SitemapChunkKeyTest extends TestCase
{
    private const string LOW = '00000000-0000-4000-8000-000000000001';
    private const string HIGH = 'ffffffff-0000-4000-8000-000000000002';

    public function testBothEndsRoundTrip(): void
    {
        $key = SitemapChunkKey::between(self::LOW, self::HIGH);

        $decoded = SitemapChunkKey::decode($key->encode());

        self::assertSame(self::LOW, $decoded->from);
        self::assertSame(self::HIGH, $decoded->to);
    }

    public function testOpenStartRoundTrips(): void
    {
        $decoded = SitemapChunkKey::decode(SitemapChunkKey::between(null, self::HIGH)->encode());

        self::assertNull($decoded->from);
        self::assertSame(self::HIGH, $decoded->to);
    }

    public function testOpenEndRoundTrips(): void
    {
        $decoded = SitemapChunkKey::decode(SitemapChunkKey::between(self::LOW, null)->encode());

        self::assertSame(self::LOW, $decoded->from);
        self::assertNull($decoded->to);
    }

    public function testBothEndsOpenRoundTrip(): void
    {
        $decoded = SitemapChunkKey::decode(SitemapChunkKey::between(null, null)->encode());

        self::assertNull($decoded->from);
        self::assertNull($decoded->to);
    }

    public function testKeyIsBase64UrlWithoutPaddingOfTheJsonObject(): void
    {
        $text = SitemapChunkKey::between(self::LOW, null)->encode();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $text);
        self::assertSame(
            ['f' => self::LOW, 't' => null],
            json_decode((string) base64_decode(strtr($text, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testEndsAreLowerCased(): void
    {
        $decoded = SitemapChunkKey::decode(SitemapChunkKey::between(strtoupper(self::LOW), strtoupper(self::HIGH))->encode());

        self::assertSame(self::LOW, $decoded->from);
        self::assertSame(self::HIGH, $decoded->to);
    }

    #[DataProvider('invalidTexts')]
    public function testInvalidTextIsRejected(string $text): void
    {
        $this->expectException(InvalidSitemapChunk::class);

        SitemapChunkKey::decode($text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTexts(): iterable
    {
        $encode = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        yield 'empty' => [''];
        yield 'not base64' => ['!!!not base64!!!'];
        yield 'padding' => [$encode('{"f":null,"t":null}').'='];
        yield 'bad base64 alphabet' => ['a+b/c'];
        yield 'not json' => [$encode('not json')];
        yield 'json scalar' => [$encode('"text"')];
        yield 'json list' => [$encode('[null,null]')];
        yield 'missing start' => [$encode('{"t":null}')];
        yield 'missing end' => [$encode('{"f":null}')];
        yield 'extra member' => [$encode('{"f":null,"t":null,"x":1}')];
        yield 'start is not a string' => [$encode('{"f":1,"t":null}')];
        yield 'end is not a string' => [$encode('{"f":null,"t":false}')];
        yield 'start is not a uuid' => [$encode('{"f":"abc","t":null}')];
        yield 'end is not a uuid' => [$encode('{"f":null,"t":"'.self::HIGH.'x"}')];
        yield 'end with a newline' => [$encode('{"f":null,"t":"'.self::HIGH."\n".'"}')];
        yield 'start equals end' => [$encode('{"f":"'.self::LOW.'","t":"'.self::LOW.'"}')];
        yield 'start differs from end by case only' => [$encode('{"f":"'.self::LOW.'","t":"'.strtoupper(self::LOW).'"}')];
        yield 'start above end' => [$encode('{"f":"'.self::HIGH.'","t":"'.self::LOW.'"}')];
        yield 'over-long text' => [str_repeat('A', 1000)];
        yield 'deeply nested json' => [$encode('{"f":{"a":{"b":1}},"t":null}')];
    }

    public function testInvalidKeyMessageDoesNotContainTheSubmittedText(): void
    {
        try {
            SitemapChunkKey::decode('secret-text-!');
            self::fail('The key must be rejected.');
        } catch (InvalidSitemapChunk $invalid) {
            self::assertStringNotContainsString('secret', $invalid->getMessage());
        }
    }

    public function testEncodingRefusesAnInvalidRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SitemapChunkKey::between(self::HIGH, self::LOW);
    }
}
