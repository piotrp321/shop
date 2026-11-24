<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Application\Demo\DemoPromotion;
use App\Pricing\Application\Demo\DemoPromotionList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DemoPromotionListTest extends TestCase
{
    public function testAPromotionMatchesTheKeysOfAnyOfItsPatterns(): void
    {
        $list = new DemoPromotionList([
            ['name' => 'Namioty', 'percentOff' => 20, 'days' => 30, 'patterns' => ['namiot-*', 'tipi-*/standard']],
        ]);

        [$promotion] = $list->promotions();

        self::assertSame('Namioty', $promotion->name);
        self::assertSame(20, $promotion->percentOff);
        self::assertSame(30, $promotion->days);
        self::assertTrue($promotion->matches('namiot-turystyczny-norvane-biwak-2-zielony/standard'));
        self::assertTrue($promotion->matches('tipi-a/standard'));
        self::assertFalse($promotion->matches('tipi-a/czerwone'), 'the whole key has to match');
        self::assertFalse($promotion->matches('hantle-regulowane-lumora-para-10/standard'));
    }

    /**
     * @param list<string> $patterns
     */
    #[DataProvider('invalidPromotions')]
    public function testAnInvalidPromotionIsRejected(string $name, int $percentOff, int $days, array $patterns, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new DemoPromotionList([['name' => $name, 'percentOff' => $percentOff, 'days' => $days, 'patterns' => $patterns]]);
    }

    /** @return iterable<string, array{string, int, int, list<string>, string}> */
    public static function invalidPromotions(): iterable
    {
        yield 'blank name' => ['  ', 20, 30, ['namiot-*'], 'name'];
        yield 'name of U+200B only' => ["\u{200B}", 20, 30, ['namiot-*'], 'name'];
        yield 'name of U+3164 only' => ["\u{3164}", 20, 30, ['namiot-*'], 'name'];
        yield 'name of U+FEFF and spaces' => [" \u{FEFF}\u{A0}", 20, 30, ['namiot-*'], 'name'];
        yield 'name with U+2028' => ["Namioty\u{2028}tanio", 20, 30, ['namiot-*'], 'name'];
        yield 'name with U+2029' => ["Namioty\u{2029}tanio", 20, 30, ['namiot-*'], 'name'];
        yield 'name with U+0085' => ["Namioty\u{85}tanio", 20, 30, ['namiot-*'], 'name'];
        yield 'name with U+009F' => ["Namioty\u{9F}", 20, 30, ['namiot-*'], 'name'];
        yield 'name with NUL' => ["Namioty\0", 20, 30, ['namiot-*'], 'name'];
        yield 'name with a tab' => ["Namioty\ttanio", 20, 30, ['namiot-*'], 'name'];
        yield 'name that is not UTF-8' => ["Namioty\xFF", 20, 30, ['namiot-*'], 'name'];
        yield 'name above the limit once trimmed' => ["\u{200B}".str_repeat('a', 101), 20, 30, ['namiot-*'], 'name'];
        yield 'name above the limit' => [str_repeat('a', 101), 20, 30, ['namiot-*'], 'name'];
        yield 'percent zero' => ['Namioty', 0, 30, ['namiot-*'], 'Namioty'];
        yield 'percent above the maximum' => ['Namioty', 91, 30, ['namiot-*'], 'Namioty'];
        yield 'zero days' => ['Namioty', 20, 0, ['namiot-*'], 'days'];
        yield 'more days than a promotion may last' => ['Namioty', 20, 91, ['namiot-*'], 'days'];
        yield 'no patterns' => ['Namioty', 20, 30, [], 'pattern'];
        yield 'match-all pattern' => ['Namioty', 20, 30, ['*'], 'letter or digit'];
        yield 'upper case pattern' => ['Namioty', 20, 30, ['Namiot-*'], 'Namiot-*'];
    }

    /**
     * The name is the one the domain keeps: trimmed by the rule of texts, not only of ASCII white space, and within the
     * limit counted after the trimming; a joiner of an emoji stays.
     */
    public function testANameIsTheOneThePromotionKeeps(): void
    {
        $list = new DemoPromotionList([
            ['name' => " \u{200B}\u{A0}Namioty\u{FEFF} ", 'percentOff' => 20, 'days' => 30, 'patterns' => ['namiot-*']],
            ['name' => "\u{FEFF}".str_repeat('a', 100), 'percentOff' => 20, 'days' => 30, 'patterns' => ['kajak-*']],
            ['name' => "Zestaw 👨\u{200D}👩", 'percentOff' => 20, 'days' => 30, 'patterns' => ['zestaw-*']],
        ]);

        self::assertSame(['Namioty', str_repeat('a', 100), "Zestaw 👨\u{200D}👩"], array_map(static fn (DemoPromotion $promotion): string => $promotion->name, $list->promotions()));
    }

    public function testEmptyListIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DemoPromotionList([]);
    }
}
