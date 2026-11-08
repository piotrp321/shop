<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

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
        yield 'name above the limit' => [str_repeat('a', 101), 20, 30, ['namiot-*'], 'name'];
        yield 'percent zero' => ['Namioty', 0, 30, ['namiot-*'], 'Namioty'];
        yield 'percent above the maximum' => ['Namioty', 91, 30, ['namiot-*'], 'Namioty'];
        yield 'zero days' => ['Namioty', 20, 0, ['namiot-*'], 'days'];
        yield 'more days than a promotion may last' => ['Namioty', 20, 91, ['namiot-*'], 'days'];
        yield 'no patterns' => ['Namioty', 20, 30, [], 'pattern'];
        yield 'match-all pattern' => ['Namioty', 20, 30, ['*'], 'letter or digit'];
        yield 'upper case pattern' => ['Namioty', 20, 30, ['Namiot-*'], 'Namiot-*'];
    }

    public function testEmptyListIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DemoPromotionList([]);
    }
}
