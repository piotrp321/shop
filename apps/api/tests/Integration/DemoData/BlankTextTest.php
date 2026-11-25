<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Verify\BlankText;
use App\Shared\Domain\Text;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "No value" of a text column in demo-data:verify is the rule of the texts (T3), checked in PostgreSQL, which has no
 * Unicode classes: the pattern names the invisible code points, and this test holds it to `Text` for every one of them.
 */
final class BlankTextTest extends KernelTestCase
{
    public function testThePatternLeavesOutExactlyTheCodePointsTheRuleOfTheTextsCallsEmpty(): void
    {
        $connection = $this->connection();
        $blank = array_map(
            static fn (mixed $codePoint): int => (int) $codePoint,
            $connection->fetchFirstColumn(
                'SELECT g FROM generate_series(1, 1114111) AS g WHERE (g < 55296 OR g > 57343) AND chr(g) !~ '.$connection->quote(BlankText::visiblePattern()).' ORDER BY g',
            ),
        );

        $expected = [];
        for ($codePoint = 1; $codePoint <= 0x10FFFF; ++$codePoint) {
            if (($codePoint < 0xD800 || $codePoint > 0xDFFF) && '' === Text::normalize(mb_chr($codePoint, 'UTF-8'))) {
                $expected[] = $codePoint;
            }
        }

        self::assertSame($expected, $blank);
    }

    /**
     * @return iterable<string, array{?string, bool}> the value of the column, whether it holds no value
     */
    public static function values(): iterable
    {
        yield 'NULL' => [null, true];
        yield 'an empty text' => ['', true];
        yield 'spaces' => ['   ', true];
        yield 'a no-break space' => ["\u{00A0}", true];
        yield 'an em space and a zero width space' => ["\u{2003}\u{200B}", true];
        yield 'an ideographic space and invisible characters' => ["\u{3000}\u{200D}\u{FE0F}\u{3164}", true];
        yield 'a tab and a line separator' => ["\t\u{2028}", true];
        yield 'a text' => ['Hallen', false];
        yield 'a text with spaces around' => [" Hallen\u{00A0}", false];
        yield 'a polish letter' => ['ł', false];
        yield 'an emoji with a joiner' => ["\u{1F468}\u{200D}\u{1F469}", false];
        yield 'a character outside the basic plane' => ["\u{1F600}", false];
        yield 'a code of digits' => ['5901234123457', false];
    }

    #[DataProvider('values')]
    public function testTheConditionHoldsForAValueWithoutAVisibleCharacter(?string $value, bool $missing): void
    {
        $connection = $this->connection();
        $condition = BlankText::missing('v', $connection->quote(BlankText::visiblePattern()));

        $result = $connection->fetchOne('SELECT '.$condition.' FROM (SELECT CAST(? AS text) AS v) AS t', [$value]);

        self::assertSame($missing, (bool) $result);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
