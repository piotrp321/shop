<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Application\Demo\DemoPriceList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DemoPriceListTest extends TestCase
{
    public function testFirstMatchingPatternInFileOrderWins(): void
    {
        $list = new DemoPriceList([
            ['pattern' => 'przewodnik-*-tatry-zachodnie-*', 'gross' => 5490],
            ['pattern' => 'przewodnik-*', 'gross' => 3990],
        ]);

        self::assertSame(5490, $list->grossFor('przewodnik-tarvik-press-tatry-zachodnie-oprawa-twarda'));
        self::assertSame(3990, $list->grossFor('przewodnik-tarvik-press-bieszczady-oprawa-twarda'));
    }

    public function testPatternMatchesTheWholeSlug(): void
    {
        $list = new DemoPriceList([['pattern' => 'kask-*-czarny', 'gross' => 19900]]);

        self::assertSame(19900, $list->grossFor('kask-rowerowy-zolvia-wiatr-czarny'));
        self::assertNull($list->grossFor('kask-rowerowy-zolvia-wiatr-czarny-mat'));
        self::assertNull($list->grossFor('duzy-kask-rowerowy-czarny'));
    }

    /**
     * The key of a variant is `<product slug>/<label slug>`: `*` runs over the slash, and a pattern may name the
     * variant part.
     */
    public function testPatternMatchesTheVariantKeyAndTheWildcardRunsOverTheSlash(): void
    {
        $list = new DemoPriceList([
            ['pattern' => 'przewodnik-*/oprawa-twarda', 'gross' => 5990],
            ['pattern' => 'przewodnik-*-oprawa-miekka/standard', 'gross' => 4490],
            ['pattern' => 'mapa-*', 'gross' => 2490],
        ]);

        self::assertSame(5990, $list->grossFor('przewodnik-tarvik-press-bieszczady/oprawa-twarda'));
        self::assertSame(4490, $list->grossFor('przewodnik-tarvik-press-bieszczady-oprawa-miekka/standard'));
        self::assertSame(2490, $list->grossFor('mapa-turystyczna-tarvik-press-gorce/laminowana'));
        self::assertNull($list->grossFor('przewodnik-tarvik-press-bieszczady/oprawa-miekka'));
        self::assertNull($list->grossFor('przewodnik-tarvik-press-bieszczady-oprawa-miekka/czarny'));
    }

    public function testSlugWithoutMatchingPatternHasNoPrice(): void
    {
        $list = new DemoPriceList([['pattern' => 'mapa-*', 'gross' => 2490]]);

        self::assertNull($list->grossFor('namiot-turystyczny-norvane-biwak-2-zielony'));
    }

    public function testWildcardDoesNotTreatOtherCharactersAsRegex(): void
    {
        $list = new DemoPriceList([['pattern' => 'a-*-b', 'gross' => 100]]);

        self::assertNull($list->grossFor('a-b'));
        self::assertSame(100, $list->grossFor('a--b'));
    }

    #[DataProvider('invalidRules')]
    public function testInvalidRuleIsRejected(string $pattern, int $gross, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new DemoPriceList([['pattern' => 'mapa-*', 'gross' => 2490], ['pattern' => $pattern, 'gross' => $gross]]);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidRules(): iterable
    {
        yield 'gross zero' => ['namiot-*', 0, 'namiot-*'];
        yield 'gross above the maximum' => ['namiot-*', 100_000_000, 'namiot-*'];
        yield 'match-all pattern' => ['*', 100, 'letter or digit'];
        yield 'only wildcards and dashes' => ['*-*', 100, 'letter or digit'];
        yield 'upper case' => ['Namiot-*', 100, 'Namiot-*'];
        yield 'regex characters' => ['namiot-.+', 100, 'namiot-.+'];
        yield 'only wildcards, dashes and slashes' => ['*/*', 100, 'letter or digit'];
        yield 'backslash' => ['namiot-*\\standard', 100, 'namiot-*\\standard'];
        yield 'empty' => ['', 100, 'letter or digit'];
    }

    public function testEmptyListIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DemoPriceList([]);
    }
}
