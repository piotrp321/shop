<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Demo;

use App\Inventory\Application\Demo\DemoStockList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DemoStockListTest extends TestCase
{
    public function testFirstMatchingPatternInFileOrderWins(): void
    {
        $list = new DemoStockList([
            ['pattern' => 'kajak-*-zielony', 'quantity' => 0],
            ['pattern' => 'kajak-*', 'quantity' => 30],
        ]);

        self::assertSame(0, $list->quantityFor('kajak-vilmar-rzeka-2-zielony'));
        self::assertSame(30, $list->quantityFor('kajak-vilmar-rzeka-2-pomaranczowy'));
    }

    public function testPatternMatchesTheWholeSlug(): void
    {
        $list = new DemoStockList([['pattern' => 'kask-*-czarny', 'quantity' => 25]]);

        self::assertSame(25, $list->quantityFor('kask-rowerowy-zolvia-wiatr-czarny'));
        self::assertNull($list->quantityFor('kask-rowerowy-zolvia-wiatr-czarny-mat'));
        self::assertNull($list->quantityFor('duzy-kask-rowerowy-czarny'));
    }

    public function testSlugWithoutMatchingPatternHasNoQuantity(): void
    {
        $list = new DemoStockList([['pattern' => 'mapa-*', 'quantity' => 25]]);

        self::assertNull($list->quantityFor('namiot-turystyczny-norvane-biwak-2-zielony'));
    }

    public function testZeroIsAQuantityNotAMissingOne(): void
    {
        $list = new DemoStockList([['pattern' => 'mapa-*', 'quantity' => 0]]);

        self::assertSame(0, $list->quantityFor('mapa-turystyczna'));
    }

    public function testWildcardDoesNotTreatOtherCharactersAsRegex(): void
    {
        $list = new DemoStockList([['pattern' => 'a-*-b', 'quantity' => 10]]);

        self::assertNull($list->quantityFor('a-b'));
        self::assertSame(10, $list->quantityFor('a--b'));
    }

    #[DataProvider('invalidRules')]
    public function testInvalidRuleIsRejected(string $pattern, int $quantity, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new DemoStockList([['pattern' => 'mapa-*', 'quantity' => 25], ['pattern' => $pattern, 'quantity' => $quantity]]);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidRules(): iterable
    {
        yield 'negative quantity' => ['namiot-*', -1, 'namiot-*'];
        yield 'quantity above the receipt maximum' => ['namiot-*', 100_001, 'namiot-*'];
        yield 'match-all pattern' => ['*', 10, 'letter or digit'];
        yield 'only wildcards and dashes' => ['*-*', 10, 'letter or digit'];
        yield 'upper case' => ['Namiot-*', 10, 'Namiot-*'];
        yield 'regex characters' => ['namiot-.+', 10, 'namiot-.+'];
        yield 'empty' => ['', 10, 'letter or digit'];
    }

    public function testTheMaximumReceiptIsAccepted(): void
    {
        self::assertSame(100_000, new DemoStockList([['pattern' => 'mapa-*', 'quantity' => 100_000]])->quantityFor('mapa-a'));
    }

    public function testEmptyListIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DemoStockList([]);
    }
}
